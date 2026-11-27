<?php

namespace App\Payments;

use App\Models\Booking;
use Illuminate\Support\Str;

/*
| Stand-in gateway for development and tests: approves every payment at
| once, without leaving our site.
|
| Safety net: in production it declines everything, so a forgotten
| PAYMENT_GATEWAY=fake can never hand out free tours.
*/
final class FakePaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly bool $approve = true,
    ) {}

    public static function decliningAll(): self
    {
        return new self(approve: false);
    }

    public function startPayment(Booking $booking): PaymentStart
    {
        if (app()->isProduction()) {
            return PaymentStart::declined('Card payments are not configured.');
        }

        return $this->approve
            ? PaymentStart::paid('fake_'.Str::lower(Str::random(20)))
            : PaymentStart::declined('The card was declined.');
    }
}
