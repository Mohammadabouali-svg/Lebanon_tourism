<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_a_link_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'maya@example.com']);

        $known = $this->postJson('/api/forgot-password', ['email' => 'maya@example.com'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_the_emailed_token_resets_the_password_and_ends_all_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'maya@example.com']);
        DB::table('sessions')->insert([
            'id' => 'stolen-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->postJson('/api/forgot-password', ['email' => 'maya@example.com']);

        $token = null;
        Notification::assertSentTo($user, function (ResetPasswordNotification $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'maya@example.com',
            'password' => 'brand-new-secret',
            'password_confirmation' => 'brand-new-secret',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-secret', $user->password));
        $this->assertTrue($user->hasVerifiedEmail(), 'Using the e-mailed link proves the address works.');
        $this->assertDatabaseMissing('sessions', ['id' => 'stolen-session']);
    }

    /*
    | The original project's critical bug: knowing someone's e-mail address was
    | enough to set a new password for their account. Here every reset needs
    | the secret token that only the inbox received.
    */
    public function test_a_password_cannot_be_reset_without_the_emailed_token(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);
        $this->postJson('/api/forgot-password', ['email' => 'victim@example.com']);

        $this->postJson('/api/reset-password', [
            'token' => 'guessed-token',
            'email' => 'victim@example.com',
            'password' => 'attacker-password',
            'password_confirmation' => 'attacker-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
