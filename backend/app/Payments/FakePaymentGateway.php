<?php

namespace App\Payments;

use Illuminate\Support\Str;

/*
| Stand-in gateway for development and tests: approves every charge.
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

    public function charge(int $amountCents, string $currency, string $description): ChargeResult
    {
        if (app()->isProduction()) {
            return ChargeResult::declined('Card payments are not configured.');
        }

        return $this->approve
            ? ChargeResult::approved('fake_'.Str::lower(Str::random(20)))
            : ChargeResult::declined('The card was declined.');
    }
}
