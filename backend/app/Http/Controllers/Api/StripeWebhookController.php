<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\BookingConfirmedNotification;
use App\Payments\StripeWebhookSignature;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/*
| POST /api/webhooks/stripe: Stripe tells us what happened to a Checkout
| Session (Exercise 9, D26).
|
| This is the ONLY way a Stripe-paid booking becomes confirmed. The browser
| returning to our success URL changes nothing: anyone can open a URL, but
| only Stripe can sign a webhook.
|
| Order matters:
|   1. verify the signature over the raw body; anything else gets 400 and
|      is never decoded;
|   2. validate the few fields we use (the event is signed, but we still
|      decide what we accept, invariant 7);
|   3. hand over to BookingService, which locks and re-checks the booking.
|
| Every event we accept gets 200, even one we ignore: Stripe retries any
| other answer for up to three days. A 400 is only for requests that are not
| from Stripe at all.
*/
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, BookingService $bookings): JsonResponse
    {
        $signature = new StripeWebhookSignature((string) config('payments.stripe.webhook_secret'));

        if (! $signature->verify($request->getContent(), $request->header('Stripe-Signature'), now()->getTimestamp())) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $event = Validator::make($request->json()->all(), [
            'type' => ['required', 'string'],
            'data.object.id' => ['required', 'string'],
        ])->validate();

        $session = $request->json('data.object');

        // A delayed payment method (a bank debit, offered by Stripe's Link
        // wallet even on a card-only session) completes the session "unpaid"
        // and reports the outcome later in an async_payment_* event. Handling
        // those too means no payment can be taken without the booking
        // hearing about it.
        match ($event['type']) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->completed($session, $bookings),
            'checkout.session.expired',
            'checkout.session.async_payment_failed' => $bookings->expireCheckout($session['id']),
            default => null, // not an event we use
        };

        return response()->json(['received' => true]);
    }

    /** @param  array<string, mixed>  $session */
    private function completed(array $session, BookingService $bookings): void
    {
        $session = Validator::make($session, [
            'id' => ['required', 'string'],
            'payment_status' => ['required', 'string'],
            'amount_total' => ['required', 'integer'],
            'currency' => ['required', 'string'],
            'payment_intent' => ['nullable', 'string'],
        ])->validate();

        // "unpaid" means a delayed payment method: the booking stays pending
        // until async_payment_succeeded (or _failed) arrives.
        if ($session['payment_status'] !== 'paid') {
            return;
        }

        $booking = $bookings->confirmCheckout(
            sessionId: $session['id'],
            // The PaymentIntent is Stripe's id for the payment itself, the
            // one to search for in the dashboard or to refund.
            paymentReference: $session['payment_intent'] ?? $session['id'],
            amountCents: $session['amount_total'],
            currency: $session['currency'],
        );

        // Only when this event confirmed it: queued, after commit.
        $booking?->load('user')->user->notify(new BookingConfirmedNotification($booking));
    }
}
