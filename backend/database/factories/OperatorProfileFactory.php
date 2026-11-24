<?php

namespace Database\Factories;

use App\Enums\OperatorStatus;
use App\Enums\UserRole;
use App\Models\OperatorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OperatorProfile> */
class OperatorProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Operator]),
            'company_name' => fake()->unique()->company().' Tours',
            'business_phone' => fake()->numerify('+961 # ### ###'),
            'status' => OperatorStatus::Approved,
            'approved_at' => now(),
        ];
    }
}
