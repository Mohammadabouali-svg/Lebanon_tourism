<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\WalletTransactionType;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Discount;
use App\Models\Tour;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| Exercise 9: card payments through Stripe Checkout (test mode).
|
| The traveler pays on Stripe's own page; the booking is confirmed only by
| Stripe's signed webhook, never by the browser coming back (D26).
|
| No test talks to the real Stripe: Http::fake() answers the API call, and
| the webhook requests are signed here with a test secret, exactly the way
| Stripe signs them.
*/
class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_feature_test';

    private const SESSION_ID = 'cs_test_a1B2c3';

    private const CHECKOUT_URL = 'https://checkout.stripe.com/c/pay/cs_test_a1B2c3';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.gateway' => 'stripe',
            'payments.stripe.secret' => 'sk_test_feature_test',
            'payments.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        // Stripe's API, faked. A test sets $stripeIsDown to simulate an outage.
        Http::preventStrayRequests();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => fn () => $this->stripeIsDown
                ? Http::response(['error' => ['message' => 'API down']], 503)
                : Http::response([
                    'id' => self::SESSION_ID,
                    'object' => 'checkout.session',
                    'url' => self::CHECKOUT_URL,
                ]),
        ]);
    }

    private bool $stripeIsDown = false;

    /** A departure of a published tour costing $90 a spot. */
    private function departure(int $capacity = 5): Departure
    {
        return Departure::factory()
            ->for(Tour::factory()->state(['title' => 'Byblos old town', 'price_cents' => 9_000]))
            ->create(['capacity' => $capacity]);
    }

    private function bookWithCard(User $traveler, Departure $departure, array $data = []): TestResponse
    {
        return $this->actingAs($traveler)->postJson(
            "/api/departures/{$departure->id}/bookings",
            $data + ['spots' => 2, 'payment_method' => 'card'],
        );
    }

    /** A pending booking waiting on Stripe, created through the real booking endpoint. */
    private function pendingBooking(?User $traveler = null, ?Departure $departure = null): Booking
    {
        $this->bookWithCard($traveler ?? $this->traveler(), $departure ?? $this->departure())->assertCreated();

        return Booking::query()->latest('id')->firstOrFail();
    }

    /** The checkout.session.* event Stripe would send for $booking. */
    private function event(string $type, Booking $booking, array $session = []): array
    {
        return [
            'id' => 'evt_'.fake()->unique()->bothify('????????'),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $session + [
                'id' => $booking->payment_session_id,
                'object' => 'checkout.session',
                'amount_total' => $booking->total_cents,
                'currency' => 'usd',
                'client_reference_id' => $booking->reference,
                'payment_intent' => 'pi_test_123',
                'payment_status' => in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) ? 'paid' : 'unpaid',
            ]],
        ];
    }

    /** Posts $event to the webhook, signed like Stripe does. */
    private function webhook(array $event, string $secret = self::WEBHOOK_SECRET, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: $body);
    }

    // ---- Starting the payment ------------------------------------------

    public function test_card_booking_with_stripe_creates_a_checkout_session_and_stays_pending(): void
    {
        Notification::fake();
        $departure = $this->departure();

        $this->bookWithCard($this->traveler(), $departure)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checkout_url', self::CHECKOUT_URL);

        $booking = Booking::sole();
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(self::SESSION_ID, $booking->payment_session_id);
        $this->assertNull($booking->payment_reference);
        $this->assertNull($booking->paid_at);
        // The spots are held while the traveler is on Stripe's page.
        $this->assertSame(2, $departure->fresh()->booked_spots);
        // Not confirmed yet, so no confirmation e-mail.
        Notification::assertNothingSent();

        Http::assertSent(function (Request $request) use ($booking) {
            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer sk_test_feature_test')
                && $request->hasHeader('Idempotency-Key', "checkout-{$booking->reference}")
                && $request['mode'] === 'payment'
                // Card only: delayed methods (bank debits) complete "unpaid"
                // and settle days later, long after the booking expired.
                && $request['allowed_payment_method_types'] === ['card']
                && $request['client_reference_id'] === $booking->reference
                && $request['line_items'][0]['quantity'] === 1
                && $request['line_items'][0]['price_data']['currency'] === 'usd'
                && $request['line_items'][0]['price_data']['unit_amount'] === 18_000
                && str_starts_with($request['success_url'], config('app.frontend_url').'/bookings');
        });
    }

    public function test_the_checkout_amount_is_the_server_quote_including_the_discount(): void
    {
        Discount::factory()->percent(25)->create(['code' => 'QUARTER']);

        // The client tries to set its own price; only the server's quote counts.
        $this->bookWithCard($this->traveler(), $this->departure(), [
            'discount_code' => 'QUARTER',
            'total_cents' => 1,
            'unit_price_cents' => 1,
        ])->assertCreated()->assertJsonPath('data.total_cents', 13_500);

        Http::assertSent(fn (Request $request) => $request['line_items'][0]['price_data']['unit_amount'] === 13_500);
    }

    public function test_stripe_being_unavailable_releases_the_spots(): void
    {
        $this->stripeIsDown = true;
        $departure = $this->departure();

        $this->bookWithCard($this->traveler(), $departure)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'payment_unavailable');

        $this->assertSame(BookingStatus::Failed, Booking::sole()->status);
        $this->assertNull(Booking::sole()->payment_session_id);
        $this->assertSame(0, $departure->fresh()->booked_spots);
    }

    public function test_the_checkout_url_is_only_shown_while_the_booking_is_pending(): void
    {
        $traveler = $this->traveler();
        $booking = $this->pendingBooking($traveler);
        $this->webhook($this->event('checkout.session.completed', $booking))->assertOk();

        $this->actingAs($traveler)->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'confirmed')
            ->assertJsonPath('data.0.checkout_url', null);
    }

    // ---- The webhook: security -----------------------------------------

    public function test_a_webhook_without_a_valid_signature_is_rejected(): void
    {
        Notification::fake();
        $booking = $this->pendingBooking();

        // An attacker who knows the session id forges the "paid" event.
        $this->webhook($this->event('checkout.session.completed', $booking), secret: 'whsec_guessed')
            ->assertStatus(400);

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->paid_at);
        Notification::assertNothingSent();
    }

    public function test_a_webhook_with_an_old_timestamp_is_rejected(): void
    {
        $booking = $this->pendingBooking();

        // A real, correctly signed request captured and replayed 10 minutes later.
        $this->webhook($this->event('checkout.session.completed', $booking), timestamp: now()->subMinutes(10)->getTimestamp())
            ->assertStatus(400);

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    // ---- The webhook: what it does -------------------------------------

    public function test_checkout_completed_confirms_the_booking_and_sends_the_email(): void
    {
        Notification::fake();
        $traveler = $this->traveler();
        $departure = $this->departure();
        $booking = $this->pendingBooking($traveler, $departure);

        $this->webhook($this->event('checkout.session.completed', $booking))->assertOk();

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('pi_test_123', $booking->payment_reference);
        $this->assertNotNull($booking->paid_at);
        $this->assertSame(2, $departure->fresh()->booked_spots);
        // Paid by card: the wallet is untouched.
        $this->assertSame(0, $traveler->wallet->transactions()->count());
        Notification::assertSentToTimes($traveler, BookingConfirmedNotification::class, 1);
    }

    public function test_a_repeated_completed_event_changes_nothing(): void
    {
        Notification::fake();
        $traveler = $this->traveler();
        $booking = $this->pendingBooking($traveler);
        $event = $this->event('checkout.session.completed', $booking);

        // Stripe delivers "at least once": the same event can arrive twice.
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(0, $traveler->wallet->fresh()->balance_cents);
        $this->assertSame(0, $traveler->wallet->transactions()->count());
        Notification::assertSentToTimes($traveler, BookingConfirmedNotification::class, 1);
    }

    public function test_a_completed_event_with_a_different_amount_does_not_confirm(): void
    {
        Notification::fake();
        $booking = $this->pendingBooking();

        $this->webhook($this->event('checkout.session.completed', $booking, ['amount_total' => 100]))->assertOk();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->payment_reference);
        Notification::assertNothingSent();
    }

    public function test_an_event_for_an_unknown_session_is_acknowledged_and_ignored(): void
    {
        $booking = $this->pendingBooking();

        // E.g. a session of another app using the same Stripe account.
        $this->webhook($this->event('checkout.session.completed', $booking, ['id' => 'cs_test_someone_else']))->assertOk();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_checkout_expired_releases_the_spots(): void
    {
        $departure = $this->departure();
        $booking = $this->pendingBooking(departure: $departure);

        $this->webhook($this->event('checkout.session.expired', $booking))->assertOk();

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
        $this->assertSame(0, $departure->fresh()->booked_spots);

        // A repeat of the expiry does not give the spots back a second time.
        $this->webhook($this->event('checkout.session.expired', $booking))->assertOk();
        $this->assertSame(0, $departure->fresh()->booked_spots);
    }

    public function test_a_payment_after_the_booking_expired_credits_the_wallet_once(): void
    {
        Notification::fake();
        $traveler = $this->traveler();
        $departure = $this->departure();
        $booking = $this->pendingBooking($traveler, $departure);

        // Our safety net expired the booking before Stripe reported the payment.
        $this->travel((int) config('payments.pending_timeout_minutes') + 1)->minutes();
        Artisan::call('bookings:expire-pending');
        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);

        $event = $this->event('checkout.session.completed', $booking);
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();

        // Still failed (its spots may belong to someone else now)...
        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
        $this->assertSame(0, $departure->fresh()->booked_spots);
        // ...but the money is not lost: travel credit, exactly once.
        $this->assertSame(18_000, $traveler->wallet->fresh()->balance_cents);
        $entry = $traveler->wallet->transactions()->sole();
        $this->assertSame(WalletTransactionType::Credit, $entry->type);
        $this->assertSame(18_000, $entry->amount_cents);
        Notification::assertNothingSent();
    }

    /*
    | Delayed payment methods (a bank debit through Stripe's Link wallet, seen
    | in the manual check despite allowed_payment_method_types=card) complete
    | the session "unpaid" and report the outcome later, in another event.
    */
    public function test_a_delayed_payment_confirms_the_booking_when_it_succeeds(): void
    {
        Notification::fake();
        $traveler = $this->traveler();
        $booking = $this->pendingBooking($traveler);

        $this->webhook($this->event('checkout.session.completed', $booking, ['payment_status' => 'unpaid']))->assertOk();
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);

        $this->webhook($this->event('checkout.session.async_payment_succeeded', $booking))->assertOk();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame('pi_test_123', $booking->fresh()->payment_reference);
        Notification::assertSentToTimes($traveler, BookingConfirmedNotification::class, 1);
    }

    public function test_a_delayed_payment_that_fails_releases_the_spots(): void
    {
        $departure = $this->departure();
        $booking = $this->pendingBooking(departure: $departure);

        $this->webhook($this->event('checkout.session.async_payment_failed', $booking))->assertOk();

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
        $this->assertSame(0, $departure->fresh()->booked_spots);
    }

    public function test_the_demo_seeder_never_calls_stripe(): void
    {
        // setUp() selected Stripe and forbids real HTTP calls: the demo card
        // bookings must go through the fake gateway and end up confirmed.
        $this->seed(); // DatabaseSeeder: the catalogue, then the demo data

        Http::assertNothingSent();
        $this->assertGreaterThan(0, Booking::query()->where('payment_method', 'card')->count());
        $this->assertSame(0, Booking::query()->where('status', BookingStatus::Pending)->count());
    }

    public function test_unhandled_event_types_are_acknowledged(): void
    {
        $booking = $this->pendingBooking();

        // Stripe retries anything that is not 2xx for days, so events we do
        // not use are answered 200 and ignored.
        $this->webhook($this->event('payment_intent.created', $booking))->assertOk();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }
}
