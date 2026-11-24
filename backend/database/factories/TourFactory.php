<?php

namespace Database\Factories;

use App\Enums\TourStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Tour;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tour> */
class TourFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'operator_id' => User::factory()->operator(),
            'city_id' => City::factory(),
            'category_id' => Category::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraphs(2, true),
            // Whole dollars between $25 and $500, stored in cents.
            'price_cents' => fake()->numberBetween(25, 500) * 100,
            'capacity' => fake()->numberBetween(8, 30),
            'booked_spots' => 0,
            'starts_on' => CarbonImmutable::today()->addDays(fake()->numberBetween(7, 90))->toDateString(),
            // Closure attributes can read other attributes of the same record.
            'ends_on' => fn (array $attributes) => CarbonImmutable::parse($attributes['starts_on'])
                ->addDays(fake()->numberBetween(0, 4))
                ->toDateString(),
            'status' => TourStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => TourStatus::Draft]);
    }

    /** A tour that started three days ago. */
    public function started(): static
    {
        return $this->state([
            'starts_on' => CarbonImmutable::today()->subDays(3)->toDateString(),
            'ends_on' => CarbonImmutable::today()->addDay()->toDateString(),
        ]);
    }
}
