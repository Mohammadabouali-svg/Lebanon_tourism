<?php

namespace App\Payments;

use App\Models\Booking;

/*
| The contract every card payment provider must fulfil.
|
| Our code never handles card numbers. With a real provider (Stripe, Adyen,
| ...) the card is typed into the provider's own form or widget; our server
| only ever sees a token or a payment id. That keeps card data off our
| servers entirely, which is both safer and a legal requirement (PCI DSS).
|
| startPayment() receives the `pending` booking that holds the spots. The
| amount to charge is the booking's total_cents, computed by the server
| (PricingService). It is called outside any transaction (invariant 14):
| it talks to another server and may be slow.
|
| Binding: AppServiceProvider decides which implementation is used, based on
| config/payments.php. Tests can swap in a gateway that declines.
*/
interface PaymentGateway
{
    public function startPayment(Booking $booking): PaymentStart;
}
