<?php

/*
| Card payments go through a "gateway" (Stripe, Adyen, PayPal, ...).
|
| The application only talks to the App\Payments\PaymentGateway interface.
| Which implementation sits behind it is chosen here, so switching from the
| fake development gateway to a real provider is a configuration change, not
| a rewrite of the booking code.
*/

return [
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    // Prices are stored in the smallest unit (cents). See docs/04-database.md.
    'currency' => 'USD',

    // A card booking holds its spots as `pending` while the card is charged.
    // One still pending after this many minutes was abandoned (the server
    // crashed between "charge" and "confirm"): bookings:expire-pending marks
    // it failed and releases the spots. Keep it well above the gateway's
    // own request timeout, or a slow but successful charge would be expired.
    'pending_timeout_minutes' => 15,
];
