<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\WalletTransactionType;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Discount;
use App\Models\Tour;
use App\Models\User;
use App\Payments\ChargeResult;
use App\Payments\PaymentGateway;
use App\Services\BookingService;
use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/*
| Exercise 5: a card booking that is still `pending` after 15 minutes was
| abandoned (the server crashed between "charge" and "confirm"). The
| bookings:expire-pending command marks it `failed` and gives its spots and
| its discount use back.
|
| To get a real stuck booking, the tests do not insert a `pending` row by
| hand: they book through BookingService with a gateway that throws in the
| middle of charge(), exactly like a crash. The booking is left `pending`
| with its spots and discount use held, as it would be in production.
*/
class ExpirePendingBookingsTest extends TestCase
{
    use RefreshDatabase;

    /** Books with a card and "crashes" during the charge. Returns the stuck booking. */
    private function bookAndCrashDuringCharge(User $traveler, Departure $departure, int $spots, ?string $discountCode = null): Booking
    {
        $this->useGateway(fn () => throw new RuntimeException('Simulated crash during the charge'));

        try {
            app(BookingService::class)->book($traveler, $departure, $spots, PaymentMethod::Card, $discountCode);
            $this->fail('The simulated crash did not happen.');
        } catch (RuntimeException) {
            // Expected: the request "died" here.
        }

        return Booking::query()->where('user_id', $traveler->id)->latest('id')->firstOrFail();
    }

    /** Replaces the payment gateway with one whose charge() runs $onCharge. */
    private function useGateway(Closure $onCharge): void
    {
        $this->app->instance(PaymentGateway::class, new class($onCharge) implements PaymentGateway
        {
            public function __construct(private readonly Closure $onCharge) {}

            public function charge(int $amountCents, string $currency, string $description): ChargeResult
            {
                return ($this->onCharge)();
            }
        });
    }

    public function test_a_pending_booking_older_than_15_minutes_is_marked_failed_and_its_spots_released(): void
    {
        $departure = Departure::factory()->create(['capacity' => 5]);
        $booking = $this->bookAndCrashDuringCharge($this->traveler(), $departure, spots: 2);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(2, $departure->fresh()->booked_spots);

        $this->travel(20)->minutes();

        $this->artisan('bookings:expire-pending')
            ->expectsOutputToContain('Expired 1 stale pending booking(s).')
            ->assertSuccessful();

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->paid_at);
        $this->assertSame(0, $departure->fresh()->booked_spots);
    }

    public function test_expiring_a_booking_releases_its_discount_use_so_the_code_can_be_used_again(): void
    {
        $discount = Discount::factory()->percent(10)->create(['code' => 'ONCE', 'max_redemptions' => 1]);
        $traveler = $this->traveler(balanceCents: 1_000_000);
        $departure = Departure::factory()->create(['capacity' => 5]);
        $this->bookAndCrashDuringCharge($traveler, $departure, spots: 1, discountCode: 'ONCE');
        $this->assertSame(1, $discount->fresh()->redemptions_count);

        $this->travel(20)->minutes();
        $this->artisan('bookings:expire-pending')->assertSuccessful();

        $this->assertSame(0, $discount->fresh()->redemptions_count);

        // The partial unique index only counts pending/confirmed bookings,
        // so the same traveler can now use the code for a real booking.
        $this->actingAs($traveler)
            ->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet', 'discount_code' => 'ONCE'])
            ->assertCreated();
    }

    public function test_a_pending_booking_younger_than_15_minutes_is_left_alone(): void
    {
        $departure = Departure::factory()->create(['capacity' => 5]);
        $booking = $this->bookAndCrashDuringCharge($this->traveler(), $departure, spots: 2);

        // Its payment may still be in progress.
        $this->travel(10)->minutes();

        $this->artisan('bookings:expire-pending')
            ->expectsOutputToContain('Expired 0 stale pending booking(s).')
            ->assertSuccessful();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertSame(2, $departure->fresh()->booked_spots);
    }

    public function test_confirmed_cancelled_and_failed_bookings_are_left_alone(): void
    {
        $departure = Departure::factory()->create(['capacity' => 10]);
        $departure->forceFill(['booked_spots' => 3])->save();
        $bookings = collect([BookingStatus::Confirmed, BookingStatus::Cancelled, BookingStatus::Failed])
            ->map(fn (BookingStatus $status) => Booking::factory()->for($departure)->create([
                'status' => $status,
                'payment_method' => PaymentMethod::Card,
            ]));

        $this->travel(1)->days();
        $this->artisan('bookings:expire-pending')
            ->expectsOutputToContain('Expired 0 stale pending booking(s).')
            ->assertSuccessful();

        $this->assertSame(
            [BookingStatus::Confirmed, BookingStatus::Cancelled, BookingStatus::Failed],
            $bookings->map(fn (Booking $booking) => $booking->fresh()->status)->all(),
        );
        $this->assertSame(3, $departure->fresh()->booked_spots);
    }

    public function test_running_the_command_twice_releases_the_spots_only_once(): void
    {
        $departure = Departure::factory()->create(['capacity' => 5]);
        $departure->forceFill(['booked_spots' => 1])->save(); // someone else's confirmed spot
        $booking = $this->bookAndCrashDuringCharge($this->traveler(), $departure, spots: 2);
        $this->assertSame(3, $departure->fresh()->booked_spots);

        $this->travel(20)->minutes();
        $this->artisan('bookings:expire-pending')->expectsOutputToContain('Expired 1 stale pending booking(s).');
        $this->artisan('bookings:expire-pending')->expectsOutputToContain('Expired 0 stale pending booking(s).');

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
        $this->assertSame(1, $departure->fresh()->booked_spots);
    }

    public function test_a_charge_that_succeeds_after_expiry_does_not_confirm_the_booking(): void
    {
        Notification::fake();
        $traveler = $this->traveler();
        $departure = Departure::factory()->for(Tour::factory()->state(['price_cents' => 9_000]))->create(['capacity' => 5]);

        // A very slow payment: while the card is being charged, 20 minutes
        // pass and the scheduled command expires the booking.
        $this->useGateway(function () {
            $this->travel(20)->minutes();
            Artisan::call('bookings:expire-pending');

            return ChargeResult::approved('late_charge');
        });

        $this->actingAs($traveler)
            ->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 2, 'payment_method' => 'card'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'booking_expired');

        // The booking stays failed and the released spots are not taken again.
        $booking = Booking::sole();
        $this->assertSame(BookingStatus::Failed, $booking->status);
        $this->assertNull($booking->paid_at);
        $this->assertSame(0, $departure->fresh()->booked_spots);

        // The card was charged, so the money comes back as wallet credit.
        $this->assertSame(18_000, $traveler->wallet->fresh()->balance_cents);
        $entry = $traveler->wallet->transactions()->sole();
        $this->assertSame(WalletTransactionType::Credit, $entry->type);
        $this->assertSame(18_000, $entry->amount_cents);
        $this->assertTrue($entry->reference->is($booking));

        Notification::assertNothingSent();
    }

    public function test_the_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'bookings:expire-pending'));

        $this->assertNotNull($event, 'bookings:expire-pending is not scheduled.');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
