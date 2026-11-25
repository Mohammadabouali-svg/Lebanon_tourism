<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\OperatorStatus;
use App\Enums\WalletTransactionType;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tour;
use App\Notifications\BookingConfirmedNotification;
use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| The booking rules. Most of these tests are named after a way the original
| project could be abused, and prove that it no longer works.
|
| Since Exercise 10 a traveler books a *departure* (one date of a tour), and
| the spots are counted per departure (docs/decisions.md D23).
*/
class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function book(Departure $departure, array $data): TestResponse
    {
        return $this->postJson("/api/departures/{$departure->id}/bookings", $data + ['payment_method' => 'wallet']);
    }

    /** A departure of a published tour, with the given tour and departure attributes. */
    private function departure(array $tour = [], array $departure = []): Departure
    {
        return Departure::factory()->for(Tour::factory()->state($tour))->create($departure);
    }

    public function test_a_traveler_pays_with_the_wallet(): void
    {
        Notification::fake();
        $traveler = $this->traveler(balanceCents: 50_000);
        $departure = $this->departure(['price_cents' => 12_000], ['capacity' => 10]);

        $this->actingAs($traveler);
        $response = $this->book($departure, ['spots' => 2])->assertCreated();

        $response->assertJsonPath('data.total_cents', 24_000)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_method', 'wallet')
            ->assertJsonPath('data.departure.id', $departure->id)
            ->assertJsonPath('data.departure.starts_on', $departure->starts_on->toDateString())
            ->assertJsonPath('data.tour.id', $departure->tour_id);

        $this->assertSame(26_000, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(2, $departure->fresh()->booked_spots);

        $booking = Booking::sole();
        $this->assertSame($departure->id, $booking->departure_id);
        $this->assertSame($departure->tour_id, $booking->tour_id);

        // The ledger explains the balance change.
        $entry = $traveler->wallet->transactions()->sole();
        $this->assertSame(WalletTransactionType::Debit, $entry->type);
        $this->assertSame(24_000, $entry->amount_cents);
        $this->assertTrue($entry->reference->is($booking));

        Notification::assertSentTo($traveler, BookingConfirmedNotification::class);
    }

    public function test_the_price_sent_by_the_client_is_ignored(): void
    {
        $traveler = $this->traveler(balanceCents: 50_000);
        $departure = $this->departure(['price_cents' => 12_000]);

        $this->actingAs($traveler);
        $this->book($departure, [
            'spots' => 1,
            'price_cents' => 1,
            'base_price' => 0,
            'total_cents' => 0,
            // Neither can the client move the booking to another tour.
            'tour_id' => Tour::factory()->create(['price_cents' => 100])->id,
        ])->assertCreated()
            ->assertJsonPath('data.total_cents', 12_000)
            ->assertJsonPath('data.tour.id', $departure->tour_id);
    }

    public function test_zero_or_negative_spots_are_rejected(): void
    {
        $traveler = $this->traveler(balanceCents: 50_000);
        $departure = $this->departure();
        $this->actingAs($traveler);

        $this->book($departure, ['spots' => 0])->assertUnprocessable()->assertJsonValidationErrors('spots');
        $this->book($departure, ['spots' => -3])->assertUnprocessable()->assertJsonValidationErrors('spots');

        // In the original project, negative spots *increased* the wallet balance.
        $this->assertSame(50_000, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(0, $departure->fresh()->booked_spots);
    }

    public function test_a_departure_cannot_be_overbooked(): void
    {
        $traveler = $this->traveler(balanceCents: 1_000_000);
        $departure = $this->departure(departure: ['capacity' => 3, 'booked_spots' => 2]);

        $this->actingAs($traveler);
        $this->book($departure, ['spots' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_enough_spots');

        $this->assertSame(2, $departure->fresh()->booked_spots);
        $this->assertSame(0, Booking::count());
    }

    public function test_only_one_of_two_travelers_gets_the_last_spot(): void
    {
        // The two requests run one after the other here: PHPUnit cannot send
        // them in parallel inside RefreshDatabase's single transaction. The
        // lock that makes the parallel case safe is asserted in the next test.
        $departure = $this->departure(departure: ['capacity' => 5, 'booked_spots' => 4]);
        $first = $this->traveler(balanceCents: 1_000_000);
        $second = $this->traveler(balanceCents: 1_000_000);

        $this->actingAs($first);
        $this->book($departure, ['spots' => 1])->assertCreated();

        $this->actingAs($second);
        $this->book($departure, ['spots' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_enough_spots');

        $this->assertSame(5, $departure->fresh()->booked_spots);
        $this->assertSame([$first->id], Booking::pluck('user_id')->all());
        $this->assertSame(1_000_000, $second->wallet->fresh()->balance_cents);
    }

    public function test_the_departure_row_is_locked_before_its_spots_are_counted(): void
    {
        // Without `FOR UPDATE`, two parallel requests could both read
        // "1 spot left" and both book it (the CHECK would then reject the
        // second one with a 500 instead of a clean 422).
        $departure = $this->departure();
        $this->actingAs($this->traveler(balanceCents: 1_000_000));

        DB::enableQueryLog();
        $this->book($departure, ['spots' => 1])->assertCreated();
        $queries = array_column(DB::getQueryLog(), 'query');

        $tourShare = $this->firstIndex($queries, fn (string $sql) => str_starts_with($sql, 'select * from "tours"') && str_ends_with($sql, 'for share'));
        $departureLock = $this->firstIndex($queries, fn (string $sql) => str_starts_with($sql, 'select * from "departures"') && str_ends_with($sql, 'for update'));
        $counterUpdate = $this->firstIndex($queries, fn (string $sql) => str_starts_with($sql, 'update "departures" set "booked_spots"'));

        $this->assertNotNull($tourShare, 'The tour row is not share-locked.');
        $this->assertNotNull($departureLock, 'The departure row is not locked.');
        $this->assertNotNull($counterUpdate);
        // Always tour first, then departure: one lock order everywhere means no deadlocks.
        $this->assertLessThan($departureLock, $tourShare);
        $this->assertLessThan($counterUpdate, $departureLock);
    }

    /** @param  list<string>  $queries */
    private function firstIndex(array $queries, callable $matches): ?int
    {
        foreach ($queries as $index => $sql) {
            if ($matches($sql)) {
                return $index;
            }
        }

        return null;
    }

    public function test_spots_are_counted_per_departure(): void
    {
        $tour = Tour::factory()->create();
        $full = Departure::factory()->for($tour)->create(['capacity' => 2, 'booked_spots' => 2]);
        $next = Departure::factory()->for($tour)->create(['capacity' => 2]);
        $this->actingAs($this->traveler(balanceCents: 1_000_000));

        $this->book($full, ['spots' => 1])->assertUnprocessable()->assertJsonPath('code', 'not_enough_spots');
        $this->book($next, ['spots' => 2])->assertCreated();

        $this->assertSame(2, $full->fresh()->booked_spots);
        $this->assertSame(2, $next->fresh()->booked_spots);
    }

    public function test_the_database_refuses_a_booking_whose_tour_is_not_the_tour_of_its_departure(): void
    {
        // The composite foreign key bookings(departure_id, tour_id) →
        // departures(id, tour_id): the denormalised tour_id can never lie.
        $departure = $this->departure();
        $otherTour = Tour::factory()->create();

        $this->expectException(QueryException::class);
        Booking::factory()->create(['departure_id' => $departure->id, 'tour_id' => $otherTour->id]);
    }

    public function test_insufficient_wallet_balance_changes_nothing(): void
    {
        $traveler = $this->traveler(balanceCents: 5_000);
        $departure = $this->departure(['price_cents' => 12_000]);

        $this->actingAs($traveler);
        $this->book($departure, ['spots' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'insufficient_funds');

        // The transaction rolled back the spots that had been reserved.
        $this->assertSame(0, $departure->fresh()->booked_spots);
        $this->assertSame(5_000, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(0, Booking::count());
    }

    public function test_departures_of_draft_tours_past_departures_and_suspended_operators_are_not_bookable(): void
    {
        $traveler = $this->traveler(balanceCents: 1_000_000);
        $this->actingAs($traveler);

        $draft = Departure::factory()->for(Tour::factory()->draft())->create();
        $started = Departure::factory()->started()->create();
        $today = $this->departure(departure: ['starts_on' => today()->toDateString()]);
        $suspended = $this->departure();
        $suspended->tour->operator->operatorProfile->forceFill(['status' => OperatorStatus::Suspended])->save();

        foreach ([$draft, $started, $today, $suspended] as $departure) {
            $this->book($departure, ['spots' => 1])->assertUnprocessable()->assertJsonPath('code', 'tour_not_bookable');
            $this->assertSame(0, $departure->fresh()->booked_spots);
        }
        $this->assertSame(0, Booking::count());
    }

    public function test_booking_an_unknown_departure_is_not_found(): void
    {
        $this->actingAs($this->traveler(balanceCents: 1_000_000));

        $this->postJson('/api/departures/999999/bookings', ['spots' => 1, 'payment_method' => 'wallet'])->assertNotFound();
    }

    public function test_the_old_tour_booking_url_is_gone(): void
    {
        // The tour alone no longer says which date is booked.
        $departure = $this->departure();
        $this->actingAs($this->traveler(balanceCents: 1_000_000));

        $this->postJson("/api/tours/{$departure->tour_id}/bookings", ['spots' => 1, 'payment_method' => 'wallet'])->assertNotFound();
        $this->assertSame(0, Booking::count());
    }

    public function test_a_card_payment_confirms_the_booking(): void
    {
        $traveler = $this->traveler();
        $departure = $this->departure(['price_cents' => 9_000]);

        $this->actingAs($traveler);
        $this->book($departure, ['spots' => 1, 'payment_method' => 'card'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_method', 'card');

        $this->assertStringStartsWith('fake_', Booking::sole()->payment_reference);
        $this->assertSame(1, $departure->fresh()->booked_spots);
    }

    public function test_a_declined_card_releases_the_held_spots(): void
    {
        // Swap the payment gateway for one that declines everything.
        $this->app->instance(PaymentGateway::class, FakePaymentGateway::decliningAll());

        $traveler = $this->traveler();
        $departure = $this->departure(departure: ['capacity' => 5]);

        $this->actingAs($traveler);
        $this->book($departure, ['spots' => 2, 'payment_method' => 'card'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'payment_declined');

        $this->assertSame(0, $departure->fresh()->booked_spots);
        $this->assertSame(BookingStatus::Failed, Booking::sole()->status);
    }

    public function test_cancelling_refunds_the_wallet_and_frees_the_spots(): void
    {
        $traveler = $this->traveler(balanceCents: 30_000);
        $departure = $this->departure(['price_cents' => 10_000]);
        $this->actingAs($traveler);
        $bookingId = $this->book($departure, ['spots' => 2])->json('data.id');

        $this->postJson("/api/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.can_cancel', false);

        $this->assertSame(30_000, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(0, $departure->fresh()->booked_spots);

        // Cancelling twice must not refund twice.
        $this->postJson("/api/bookings/{$bookingId}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'booking_not_cancellable');
        $this->assertSame(30_000, $traveler->wallet->fresh()->balance_cents);
    }

    public function test_a_booking_cannot_be_cancelled_once_its_departure_has_started(): void
    {
        $traveler = $this->traveler(balanceCents: 30_000);
        $departure = $this->departure(['price_cents' => 10_000], ['starts_on' => today()->addDays(2)->toDateString()]);
        $this->actingAs($traveler);
        $bookingId = $this->book($departure, ['spots' => 1])->json('data.id');

        $this->travel(2)->days();

        $this->getJson('/api/bookings')->assertJsonPath('data.0.can_cancel', false);
        $this->postJson("/api/bookings/{$bookingId}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'booking_not_cancellable');

        $this->assertSame(20_000, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(1, $departure->fresh()->booked_spots);
    }

    public function test_a_traveler_cannot_cancel_someone_elses_booking(): void
    {
        $booking = Booking::factory()->create();

        $this->actingAs($this->traveler())
            ->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertForbidden();
    }

    public function test_travelers_only_see_their_own_bookings(): void
    {
        $traveler = $this->traveler();
        Booking::factory()->for($traveler)->create();
        Booking::factory()->count(2)->create(); // other people's

        $this->actingAs($traveler)
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
