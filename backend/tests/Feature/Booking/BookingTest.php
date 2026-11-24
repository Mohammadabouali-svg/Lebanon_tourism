<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\OperatorStatus;
use App\Enums\WalletTransactionType;
use App\Models\Booking;
use App\Models\Tour;
use App\Notifications\BookingConfirmedNotification;
use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| The booking rules. Most of these tests are named after a way the original
| project could be abused, and prove that it no longer works.
*/
class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function book(Tour $tour, array $data): TestResponse
    {
        return $this->postJson("/api/tours/{$tour->id}/bookings", $data + ['payment_method' => 'wallet']);
    }

    public function test_a_traveler_pays_with_the_wallet(): void
    {
        Notification::fake();
        $traveler = $this->traveler(balanceCents: 50_000);
        $tour = Tour::factory()->create(['price_cents' => 12_000, 'capacity' => 10]);

        $this->actingAs($traveler);
        $response = $this->book($tour, ['spots' => 2])->assertCreated();

        $response->assertJsonPath('data.total_cents', 24_000)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_method', 'wallet');

        $this->assertSame(26_000, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(2, $tour->fresh()->booked_spots);

        // The ledger explains the balance change.
        $entry = $traveler->wallet->transactions()->sole();
        $this->assertSame(WalletTransactionType::Debit, $entry->type);
        $this->assertSame(24_000, $entry->amount_cents);
        $this->assertTrue($entry->reference->is(Booking::sole()));

        Notification::assertSentTo($traveler, BookingConfirmedNotification::class);
    }

    public function test_the_price_sent_by_the_client_is_ignored(): void
    {
        $traveler = $this->traveler(balanceCents: 50_000);
        $tour = Tour::factory()->create(['price_cents' => 12_000]);

        $this->actingAs($traveler);
        $this->book($tour, [
            'spots' => 1,
            'price_cents' => 1,
            'base_price' => 0,
            'total_cents' => 0,
        ])->assertCreated()->assertJsonPath('data.total_cents', 12_000);
    }

    public function test_zero_or_negative_spots_are_rejected(): void
    {
        $traveler = $this->traveler(balanceCents: 50_000);
        $tour = Tour::factory()->create();
        $this->actingAs($traveler);

        $this->book($tour, ['spots' => 0])->assertUnprocessable()->assertJsonValidationErrors('spots');
        $this->book($tour, ['spots' => -3])->assertUnprocessable()->assertJsonValidationErrors('spots');

        // In the original project, negative spots *increased* the wallet balance.
        $this->assertSame(50_000, $traveler->wallet->fresh()->balance_cents);
    }

    public function test_a_tour_cannot_be_overbooked(): void
    {
        $traveler = $this->traveler(balanceCents: 1_000_000);
        $tour = Tour::factory()->create(['capacity' => 3, 'booked_spots' => 2]);

        $this->actingAs($traveler);
        $this->book($tour, ['spots' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_enough_spots');

        $this->assertSame(2, $tour->fresh()->booked_spots);
        $this->assertSame(0, Booking::count());
    }

    public function test_the_database_itself_refuses_overbooking(): void
    {
        // Even code that bypasses BookingService cannot exceed the capacity:
        // the CHECK constraint in the migration rejects the update.
        $tour = Tour::factory()->create(['capacity' => 3]);

        $this->expectException(QueryException::class);
        $tour->increment('booked_spots', 4);
    }

    public function test_insufficient_wallet_balance_changes_nothing(): void
    {
        $traveler = $this->traveler(balanceCents: 5_000);
        $tour = Tour::factory()->create(['price_cents' => 12_000]);

        $this->actingAs($traveler);
        $this->book($tour, ['spots' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'insufficient_funds');

        // The transaction rolled back the spots that had been reserved.
        $this->assertSame(0, $tour->fresh()->booked_spots);
        $this->assertSame(5_000, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(0, Booking::count());
    }

    public function test_draft_started_and_suspended_operator_tours_are_not_bookable(): void
    {
        $traveler = $this->traveler(balanceCents: 1_000_000);
        $this->actingAs($traveler);

        $draft = Tour::factory()->draft()->create();
        $started = Tour::factory()->started()->create();
        $suspended = Tour::factory()->create();
        $suspended->operator->operatorProfile->forceFill(['status' => OperatorStatus::Suspended])->save();

        foreach ([$draft, $started, $suspended] as $tour) {
            $this->book($tour, ['spots' => 1])->assertUnprocessable()->assertJsonPath('code', 'tour_not_bookable');
        }
    }

    public function test_a_card_payment_confirms_the_booking(): void
    {
        $traveler = $this->traveler();
        $tour = Tour::factory()->create(['price_cents' => 9_000]);

        $this->actingAs($traveler);
        $this->book($tour, ['spots' => 1, 'payment_method' => 'card'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_method', 'card');

        $this->assertStringStartsWith('fake_', Booking::sole()->payment_reference);
    }

    public function test_a_declined_card_releases_the_held_spots(): void
    {
        // Swap the payment gateway for one that declines everything.
        $this->app->instance(PaymentGateway::class, FakePaymentGateway::decliningAll());

        $traveler = $this->traveler();
        $tour = Tour::factory()->create(['capacity' => 5]);

        $this->actingAs($traveler);
        $this->book($tour, ['spots' => 2, 'payment_method' => 'card'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'payment_declined');

        $this->assertSame(0, $tour->fresh()->booked_spots);
        $this->assertSame(BookingStatus::Failed, Booking::sole()->status);
    }

    public function test_cancelling_refunds_the_wallet_and_frees_the_spots(): void
    {
        $traveler = $this->traveler(balanceCents: 30_000);
        $tour = Tour::factory()->create(['price_cents' => 10_000]);
        $this->actingAs($traveler);
        $bookingId = $this->book($tour, ['spots' => 2])->json('data.id');

        $this->postJson("/api/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(30_000, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(0, $tour->fresh()->booked_spots);

        // Cancelling twice must not refund twice.
        $this->postJson("/api/bookings/{$bookingId}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'booking_not_cancellable');
        $this->assertSame(30_000, $traveler->wallet->fresh()->balance_cents);
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
