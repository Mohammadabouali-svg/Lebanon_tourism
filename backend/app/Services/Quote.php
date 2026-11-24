<?php

namespace App\Services;

use App\Models\Discount;

/** The price breakdown of a (possible) booking. All amounts in cents. */
final readonly class Quote
{
    public function __construct(
        public int $unitPriceCents,
        public int $spots,
        public int $subtotalCents,
        public int $discountCents,
        public int $totalCents,
        public ?Discount $discount,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'unit_price_cents' => $this->unitPriceCents,
            'spots' => $this->spots,
            'subtotal_cents' => $this->subtotalCents,
            'discount_cents' => $this->discountCents,
            'total_cents' => $this->totalCents,
            'discount_code' => $this->discount?->code,
        ];
    }
}
