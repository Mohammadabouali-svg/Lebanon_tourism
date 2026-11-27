# Exercise 9: A real payment provider (Stripe Checkout)

## What was asked

> Implement `StripePaymentGateway` against Stripe's test mode. Use Stripe
> Checkout (Stripe hosts the card form), confirm bookings from Stripe's
> webhook rather than from the browser redirect, and verify the webhook
> signature. Bind it in `AppServiceProvider` when `PAYMENT_GATEWAY=stripe`.

## What we found first

| Finding | Where | Consequence |
|---|---|---|
| `PaymentGateway::charge()` was synchronous: approved or declined at once | `app/Payments` | A hosted page answers later, by webhook: the interface had to change |
| `markPaid()` treated "booking not pending" as "late charge, credit the wallet" | `BookingService` | A repeated `completed` webhook would refund a confirmed booking |
| A Checkout Session must stay open at least 30 minutes; our pending timeout was 15 | Stripe, D18 | A traveler paying at minute 20 would find the booking expired |
| The confirmation e-mail was sent by `BookingController::store` | controller | With Stripe the confirmation happens in the webhook |
| `DemoSeeder` books card bookings through `BookingService` | seeder | With Stripe on, `make fresh` would call Stripe |

## Decisions

Full record: [D26](../decisions.md#d26-stripe-checkout-confirmed-only-by-the-signed-webhook).

| Question | Chosen | Rejected |
|---|---|---|
| How to talk to Stripe | Laravel `Http` client, hand-written signature check (no dependency, `Http::fake()` in tests) | `stripe/stripe-php` SDK |
| 30-minute minimum vs 15-minute timeout | Sessions expire after 31 min; timeout 35 min with Stripe, still 15 with the fake gateway | Keep 15 and expire sessions through Stripe's API from the command |
| Repeated webhooks | State on the booking: `payment_reference` set means "already recorded", plus a unique index | A `processed_webhook_events` table |
| Abandoned checkout | Spots held until Stripe's `expired` event (or the command); "Continue to payment" link | A "cancel payment" button (follow-up) |
| What confirms a booking | Only the signed webhook | The success redirect (anyone can open a URL) |

## What was done

| Layer | Change |
|---|---|
| Migration `2026_11_27_000100` | `bookings.payment_session_id` (unique), `checkout_url`; `payment_reference` unique; CHECK `bookings_checkout_only_for_cards`; `down()` drops them |
| Model | `Booking::$attributes` defaults for the two columns (not fillable) |
| Payments | `PaymentGateway::startPayment(Booking): PaymentStart` (paid / declined / redirect / unavailable) replaces `charge()` and `ChargeResult`; `FakePaymentGateway` updated; new `StripePaymentGateway`, `StripeWebhookSignature`, `PaymentOutcome` |
| Service | `bookWithCard()` handles the four answers; new public `confirmCheckout()` (amount and currency check) and `expireCheckout()`; shared private `settlePayment()` makes confirmation idempotent and keeps the late-payment refund (once) |
| Exception | `BusinessRuleException::paymentUnavailable()` (`payment_unavailable`) |
| HTTP | `StripeWebhookController` (signature, then validation, then service; e-mail only when this event confirmed); route `POST /api/webhooks/stripe` outside the auth groups, `throttle:webhooks` (120/min per IP) |
| Resource | `checkout_url`: only while pending, only to the booking's traveler |
| Config | `payments.stripe.*` (secret, webhook secret, 31-minute expiry, 10 s timeout); timeout 35 with Stripe; binding in `AppServiceProvider` (fails loudly without `STRIPE_SECRET`) |
| Seeder | `DemoSeeder` always uses the fake gateway |
| Docker | `stripe` service (Stripe CLI `v1.21.8`, profile `stripe`) forwarding four event types to nginx |
| Frontend | `Booking.checkout_url`; `BookingPage` navigates to Stripe; `MyBookingsPage` shows "Continue to payment", a notice on return, and `useMyBookings` refetches every 3 s while a booking is pending |
| Docs | chapter 05 (flow, webhook diagram), 01 (local setup), 07, 09 (go-live), D12/D16/D18 notes, D26, `10-exercises.md`, AGENTS.md |

## Tests

New: `tests/Unit/StripeWebhookSignatureTest.php` (8), `tests/Feature/Booking/StripeCheckoutTest.php` (16), one row in `AccessControlTest`. The test double in `ExpirePendingBookingsTest` moved to the new interface; its assertions are unchanged.

| Test | Red run (before the code) |
|---|---|
| 8 signature unit tests (valid, tampered body, wrong secret, replay > 5 min, within 5 min, several `v1`, malformed header, empty secret) | `Error`: class `StripeWebhookSignature` not found |
| `test_the_stripe_webhook_needs_no_login_but_refuses_unsigned_requests` | 404 instead of 400 |
| Booking with Stripe: session created and pending, discounted amount, Stripe unavailable | 500 (`Unknown payment gateway [stripe]`) instead of 201 / 422 |
| Webhook: bad signature, old timestamp, completed confirms + e-mail, repeat, wrong amount, unknown session, expired, late payment, unhandled type | 500 when creating the pending booking (same reason) |
| `test_a_repeated_completed_event_changes_nothing`, `..._credits_the_wallet_once` | With the `payment_reference` check removed: `18000 is identical to 0` and `36000 is identical to 18000` (double refund) |
| `test_the_demo_seeder_never_calls_stripe` | Without the seeder change: `UniqueConstraintViolationException` (two demo bookings, same faked session) |
| `allowed_payment_method_types === ['card']` (in the first test) | Assertion false (parameter not sent) |
| `test_a_delayed_payment_confirms_the_booking_when_it_succeeds`, `..._that_fails_releases_the_spots` | Booking stayed `pending` |

## Verification

- `make test`: 239 passed before; **264 passed (1037 assertions)** after. Pint clean, `npm run build` and `npm run lint` clean (0 warnings).
- Manual check against **real Stripe test mode** (sandbox), as `traveler@`, with the Stripe CLI container:

| Step | Result |
|---|---|
| Card booking, 1 spot | 201, browser on Stripe Checkout: "Tripoli Souks & Street Food, 2026-12-03 (1 spot), US$45.00" |
| Session expired with `stripe checkout sessions expire` | Signed `checkout.session.expired` → 200 → booking `failed`, spots 4 → 3 |
| Paid with test card `4242 4242 4242 4242` | Signed `checkout.session.completed` → 200 → booking `confirmed`, `pi_…` reference, e-mail "Booking … confirmed" in Mailpit once, wallet unchanged, page "Payment received: … is confirmed" |
| First try at card-only | Stripe refused `payment_method_types` (400, logged); the page showed the `payment_unavailable` message and the spot was released; fixed with `allowed_payment_method_types` |
| Card-only session | Cash App and Amazon Pay gone; Bank and Klarna still offered via Link → `async_payment_*` events added (tests above) |

Two findings came only from the live check: Stripe's current API rejects `payment_method_types`, and Link still offers delayed methods on a card-only session. `Http::fake()` cannot catch either, which is why the manual check against the real test mode matters.

Dev data: the check left bookings 7–10 for `traveler@` (one confirmed card booking, three failed); the wallet balance is unchanged ($1,243.00). Booking rows cannot be removed without `make fresh`.

## Follow-ups

- A "cancel payment" button for a pending Stripe booking (expire the session, then release the spots).
- Refunds to the card through Stripe's API instead of wallet credit (a product decision).
- Pin a newer Stripe CLI image (the CLI reports v1.55 available).
