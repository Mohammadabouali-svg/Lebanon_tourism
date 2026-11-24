<?php

namespace App\Payments;

/*
| A small immutable "value object". `readonly` means its properties cannot
| change after construction, so a result can be passed around safely.
*/
final readonly class ChargeResult
{
    private function __construct(
        public bool $successful,
        public ?string $reference,
        public ?string $failureMessage,
    ) {}

    public static function approved(string $reference): self
    {
        return new self(true, $reference, null);
    }

    public static function declined(string $message): self
    {
        return new self(false, null, $message);
    }
}
