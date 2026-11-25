<?php

namespace Database\Factories;

use App\Enums\TourStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Departure;
use App\Models\Tour;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/*
| A tour has no date or capacity of its own (Exercise 10): add departures
| with ->withDeparture() or Departure::factory()->for($tour). A tour without
| an upcoming departure is not listed in the public catalogue.
|
| @extends Factory<Tour>
*/
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
            'duration_days' => fake()->numberBetween(1, 5),
            'status' => TourStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => TourStatus::Draft]);
    }

    /** Archived by an admin and locked against operator status changes. */
    public function moderated(): static
    {
        return $this->state([
            'status' => TourStatus::Archived,
            'moderated_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    /** With one upcoming departure (or one with the given attributes). */
    public function withDeparture(array $attributes = []): static
    {
        return $this->has(Departure::factory()->state($attributes), 'departures');
    }
}
