<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Discount> */
class DiscountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('????##')),
            'description' => fake()->sentence(),
            'type' => DiscountType::Percent,
            'value' => 10,
            'max_redemptions' => null,
            'starts_at' => null,
            'expires_at' => null,
            'is_active' => true,
        ];
    }

    public function fixed(int $cents): static
    {
        return $this->state(['type' => DiscountType::Fixed, 'value' => $cents]);
    }

    public function percent(int $percent): static
    {
        return $this->state(['type' => DiscountType::Percent, 'value' => $percent]);
    }
}
