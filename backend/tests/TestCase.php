<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Make every test request look like it comes from the React app.
        // Sanctum only starts a session (cookie authentication) for requests
        // whose Origin/Referer is one of SANCTUM_STATEFUL_DOMAINS.
        // (Laravel disables the CSRF check while tests run.)
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);
    }

    /** A verified traveler with the given wallet balance. */
    protected function traveler(int $balanceCents = 0): User
    {
        return User::factory()->withBalance($balanceCents)->create();
    }
}
