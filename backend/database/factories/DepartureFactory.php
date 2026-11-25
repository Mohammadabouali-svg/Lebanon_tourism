<?php

namespace Database\Factories;

use App\Models\Departure;
use App\Models\Tour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Departure> */
class DepartureFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tour_id' => Tour::factory(),
            'starts_on' => CarbonImmutable::today()->addDays(fake()->unique()->numberBetween(7, 400))->toDateString(),
            'capacity' => fake()->numberBetween(8, 30),
            'booked_spots' => 0,
        ];
    }

    /** A departure that started three days ago. */
    public function started(): static
    {
        return $this->state(['starts_on' => CarbonImmutable::today()->subDays(3)->toDateString()]);
    }
}
