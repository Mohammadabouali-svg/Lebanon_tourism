<?php

namespace Database\Factories;

use App\Enums\OperatorStatus;
use App\Enums\UserRole;
use App\Models\OperatorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
| Factories create realistic fake records for tests and demo data:
|
|     User::factory()->create();                      // a verified traveler
|     User::factory()->admin()->create();
|     User::factory()->operator(OperatorStatus::Pending)->create();
|
| @extends Factory<User>
*/
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Hashing is slow on purpose; hash once and reuse for every fake user.
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Traveler,
            'phone' => fake()->numerify('+961 ## ### ###'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin]);
    }

    public function operator(OperatorStatus $status = OperatorStatus::Approved): static
    {
        return $this->state(['role' => UserRole::Operator])
            ->afterCreating(function (User $user) use ($status) {
                OperatorProfile::factory()->for($user)->create([
                    'status' => $status,
                    'approved_at' => $status === OperatorStatus::Approved ? now() : null,
                ]);
            });
    }

    public function suspended(): static
    {
        return $this->state(['suspended_at' => now()]);
    }

    /** Sets the wallet balance right after the user (and its wallet) is created. */
    public function withBalance(int $cents): static
    {
        return $this->afterCreating(function (User $user) use ($cents) {
            $user->wallet->forceFill(['balance_cents' => $cents])->save();
        });
    }
}
