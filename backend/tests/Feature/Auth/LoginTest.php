<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_log_in_with_a_case_insensitive_email(): void
    {
        $user = User::factory()->create(['email' => 'maya@example.com']);

        $this->postJson('/api/login', ['email' => 'Maya@Example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        User::factory()->create(['email' => 'maya@example.com']);

        $wrongPassword = $this->postJson('/api/login', ['email' => 'maya@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->json('errors.email.0');

        $unknownEmail = $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->json('errors.email.0');

        // Identical messages: an attacker cannot learn which e-mails have accounts.
        $this->assertSame($wrongPassword, $unknownEmail);
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => 'maya@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'maya@example.com', 'password' => 'guess-'.$i])->assertUnprocessable();
        }

        // Even the correct password is refused while the account is locked out.
        $this->postJson('/api/login', ['email' => 'maya@example.com', 'password' => 'password'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_a_suspended_user_cannot_log_in(): void
    {
        User::factory()->suspended()->create(['email' => 'maya@example.com']);

        $this->postJson('/api/login', ['email' => 'maya@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'suspended']);

        $this->assertGuest();
    }

    public function test_logging_out_ends_the_session(): void
    {
        User::factory()->create(['email' => 'maya@example.com']);
        $this->postJson('/api/login', ['email' => 'maya@example.com', 'password' => 'password'])->assertOk();

        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');

        // In a test, every request runs inside the same application instance,
        // and Sanctum's guard remembers the user it resolved earlier. In real
        // life each request starts fresh; forgetGuards() simulates that.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/user')->assertUnauthorized();
    }
}
