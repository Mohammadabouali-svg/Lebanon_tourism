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
];
