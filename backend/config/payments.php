<?php

/*
| Card payments go through a "gateway" (Stripe, Adyen, PayPal, ...).
|
| The application only talks to the App\Payments\PaymentGateway interface.
| Which implementation sits behind it is chosen here, so switching from the
| fake development gateway to a real provider is a configuration change, not
| a rewrite of the booking code.
|
|   fake    approves every card at once (declines in production, D12)
|   stripe  Stripe Checkout: the traveler pays on Stripe's page and Stripe's
|           signed webhook confirms the booking (Exercise 9, D26)
*/

$gateway = env('PAYMENT_GATEWAY', 'fake');

return [
    'gateway' => $gateway,

    // Prices are stored in the smallest unit (cents). See docs/04-database.md.
    // Stripe also takes amounts in cents, so no conversion is ever needed.
    'currency' => 'USD',

    // A card booking holds its spots as `pending` while it is being paid.
    // One still pending after this many minutes was abandoned:
    // bookings:expire-pending marks it failed and releases the spots.
    //
    // It must be longer than any payment can take, or a slow but successful
    // payment would be expired (it is then refunded to the wallet, D18):
    //   - fake: an instant call, so 15 minutes is plenty;
    //   - stripe: a Checkout Session stays payable until it expires (see
    //     below), so we wait a few minutes longer than that. Usually Stripe's
    //     checkout.session.expired webhook releases the spots first and the
    //     command is only the safety net.
    'pending_timeout_minutes' => $gateway === 'stripe' ? 35 : 15,

    'stripe' => [
        // Test-mode secret key (sk_test_...). Server side only, never sent
        // to the browser. Set in backend/.env, which is never committed.
        'secret' => env('STRIPE_SECRET'),

        // Signing secret of the webhook endpoint (whsec_...). Locally it is
        // printed by `stripe listen` (the `stripe` compose service).
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // How long a Checkout Session can be paid. Stripe requires at least
        // 30 minutes; 31 leaves room for clock differences between us and
        // Stripe, which would otherwise reject a session of exactly 30.
        'checkout_expires_minutes' => 31,

        // Stripe answers in well under a second. Without a timeout a hanging
        // connection would keep the traveler's request (and a PHP worker)
        // busy for the default 30 seconds.
        'timeout_seconds' => 10,
    ],
];
