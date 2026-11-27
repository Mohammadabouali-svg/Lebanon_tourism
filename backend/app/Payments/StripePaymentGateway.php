<?php

namespace App\Payments;

use App\Models\Booking;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
| Stripe Checkout in test mode (Exercise 9, D26).
|
| startPayment() does not charge anything. It asks Stripe for a Checkout
| Session (a payment page hosted by Stripe) and answers "redirect": the
| browser goes to that page, the traveler types the card there, and Stripe
| tells us the result with a signed webhook (StripeWebhookController). The
| card number never reaches our server, and the browser coming back to our
| success URL proves nothing: anyone can open a URL.
|
| One HTTP call with Laravel's Http client, no SDK (D26). The API takes
| form-encoded parameters (`line_items[0][quantity]=1`); asForm() builds them.
|
| What is sent, and why:
|   - one line item with the booking's total_cents: the server's quote,
|     discount included (invariant 2). Stripe's own coupons are not used,
|     so there is exactly one place that computes prices.
|   - client_reference_id = our booking reference, to find it in Stripe's
|     dashboard. The webhook looks bookings up by session id, not by this.
|   - expires_at: a session that is not paid in time expires, and Stripe
|     sends checkout.session.expired, which releases the spots.
|   - Idempotency-Key: if this request is retried, Stripe returns the same
|     session instead of creating a second one for the same booking.
|   - no e-mail or name of the traveler: Stripe asks on its page. We share
|     no personal data we do not have to.
*/
final readonly class StripePaymentGateway implements PaymentGateway
{
    private const SESSIONS_URL = 'https://api.stripe.com/v1/checkout/sessions';

    public function __construct(
        private string $secret,
        private string $currency,
        private string $frontendUrl,
        private int $expiresMinutes,
        private int $timeoutSeconds,
    ) {}

    public function startPayment(Booking $booking): PaymentStart
    {
        $departure = $booking->departure;

        try {
            $response = Http::asForm()
                ->withToken($this->secret)
                ->withHeaders(['Idempotency-Key' => "checkout-{$booking->reference}"])
                ->timeout($this->timeoutSeconds)
                ->post(self::SESSIONS_URL, [
                    'mode' => 'payment',
                    // Cards only. Without this, Stripe offers whatever the
                    // dashboard enables (bank debits, Klarna, ...). A bank
                    // debit completes "unpaid" and is paid days later in
                    // another event: the booking would have expired and the
                    // money would be taken anyway. Our booking says "card".
                    // (Current API versions refuse the older name
                    // `payment_method_types`; seen in the manual check.)
                    'allowed_payment_method_types' => ['card'],
                    'client_reference_id' => $booking->reference,
                    'line_items' => [[
                        'quantity' => 1,
                        'price_data' => [
                            'currency' => strtolower($this->currency),
                            'unit_amount' => $booking->total_cents,
                            'product_data' => [
                                'name' => "{$departure->tour->title}, {$departure->starts_on->toDateString()} ({$booking->spots} ".($booking->spots === 1 ? 'spot' : 'spots').')',
                            ],
                        ],
                    ]],
                    'expires_at' => now()->addMinutes($this->expiresMinutes)->getTimestamp(),
                    'success_url' => "{$this->frontendUrl}/bookings?paid={$booking->reference}",
                    'cancel_url' => "{$this->frontendUrl}/bookings",
                ]);
        } catch (ConnectionException $e) {
            Log::error('Could not reach Stripe to start a Checkout Session.', [
                'booking' => $booking->reference,
                'error' => $e->getMessage(),
            ]);

            return PaymentStart::unavailable();
        }

        $sessionId = $response->json('id');
        $url = $response->json('url');

        if ($response->failed() || ! is_string($sessionId) || ! is_string($url)) {
            // Stripe's error message, never the request (it carries the key).
            Log::error('Stripe refused to create a Checkout Session.', [
                'booking' => $booking->reference,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return PaymentStart::unavailable();
        }

        return PaymentStart::redirect($sessionId, $url);
    }
}
