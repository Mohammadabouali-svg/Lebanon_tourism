<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| Exercise 4: changing the e-mail address (docs/decisions.md D20).
|
| The rule under test: the new address replaces the old one only after a
| link sent TO the new address has been clicked. Until then the account
| keeps working with the old address, so a typo cannot lock anyone out and
| a stolen session alone cannot move the account to an attacker's inbox.
*/
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function maya(): User
    {
        return User::factory()->create(['email' => 'maya@example.com']);
    }

    private function requestChange(User $user, string $email, string $password = 'password'): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/user/email-change', [
            'email' => $email,
            'current_password' => $password,
        ]);
    }

    /** The confirmation notification that was mailed to $address. */
    private function sentConfirmation(string $address): ConfirmEmailChangeNotification
    {
        $sent = null;
        Notification::assertSentOnDemand(
            ConfirmEmailChangeNotification::class,
            function (ConfirmEmailChangeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($address, &$sent) {
                if ($notifiable->routes['mail'] === $address) {
                    $sent = $notification;
                }

                return $sent !== null;
            },
        );

        return $sent;
    }

    /** Builds the API path the React page calls from the link in the e-mail. */
    private function confirmationPath(ConfirmEmailChangeNotification $notification): string
    {
        $mailUrl = $notification->toMail(new AnonymousNotifiable)->actionUrl;
        $this->assertStringStartsWith('http://localhost:5173/confirm-email-change?', $mailUrl);
        parse_str((string) parse_url($mailUrl, PHP_URL_QUERY), $params);

        return "/api/email/change/{$params['id']}/{$params['hash']}?".http_build_query([
            'expires' => $params['expires'],
            'signature' => $params['signature'],
        ]);
    }

    /** Request a change and return the path of the link mailed to the new address. */
    private function linkFor(User $user, string $newEmail): string
    {
        $this->requestChange($user, $newEmail)->assertAccepted();

        return $this->confirmationPath($this->sentConfirmation($newEmail));
    }

    // --- Requesting a change -------------------------------------------------

    public function test_requesting_a_change_stores_the_pending_address_and_keeps_the_current_one(): void
    {
        Notification::fake();
        $user = $this->maya();

        $this->requestChange($user, 'maya.new@example.com')
            ->assertAccepted()
            ->assertJsonPath('data.email', 'maya@example.com')
            ->assertJsonPath('data.pending_email', 'maya.new@example.com');

        $user->refresh();
        $this->assertSame('maya@example.com', $user->email);
        $this->assertSame('maya.new@example.com', $user->pending_email);
    }

    public function test_the_confirmation_link_is_mailed_to_the_new_address(): void
    {
        Notification::fake();
        $user = $this->maya();

        $this->requestChange($user, 'maya.new@example.com')->assertAccepted();

        $this->sentConfirmation('maya.new@example.com');
        Notification::assertNotSentTo($user, ConfirmEmailChangeNotification::class);
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        Notification::fake();
        $user = $this->maya();

        $this->requestChange($user, 'maya.new@example.com', 'wrong-password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertNull($user->fresh()->pending_email);
        Notification::assertNothingSent();
    }

    public function test_an_address_already_in_use_is_refused_when_requesting(): void
    {
        Notification::fake();
        $user = $this->maya();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->requestChange($user, 'Taken@Example.com')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertNull($user->fresh()->pending_email);
        Notification::assertNothingSent();
    }

    public function test_the_current_address_is_refused(): void
    {
        Notification::fake();
        $user = $this->maya();

        $this->requestChange($user, ' MAYA@example.com ')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_the_pending_address_is_normalised_to_lower_case(): void
    {
        Notification::fake();
        $user = $this->maya();

        $this->requestChange($user, '  Maya.New@Example.COM ')->assertAccepted();

        $this->assertSame('maya.new@example.com', $user->fresh()->pending_email);
    }

    public function test_requesting_a_change_is_rate_limited(): void
    {
        Notification::fake();
        $user = $this->maya();

        foreach (range(1, 6) as $attempt) {
            $this->requestChange($user, "maya{$attempt}@example.com")->assertAccepted();
        }

        $this->requestChange($user, 'maya7@example.com')->assertTooManyRequests();
        $this->assertSame('maya6@example.com', $user->fresh()->pending_email);
    }

    public function test_a_pending_change_can_be_cancelled(): void
    {
        Notification::fake();
        $user = $this->maya();
        $this->requestChange($user, 'maya.new@example.com')->assertAccepted();

        $this->actingAs($user)->deleteJson('/api/user/email-change')
            ->assertOk()
            ->assertJsonPath('data.pending_email', null);

        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_the_profile_endpoint_ignores_an_email_field(): void
    {
        $user = $this->maya();

        $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => 'Maya',
            'email' => 'attacker@example.com',
        ])->assertOk();

        $this->assertSame('maya@example.com', $user->fresh()->email);
    }

    // --- Confirming (the link) -----------------------------------------------

    public function test_a_valid_link_swaps_the_address_and_clears_the_pending_one(): void
    {
        Notification::fake();
        $user = $this->maya();
        $path = $this->linkFor($user, 'maya.new@example.com');

        // Clicked on another device: no login needed.
        $this->app['auth']->forgetGuards();
        $this->postJson($path)->assertOk();

        $user->refresh();
        $this->assertSame('maya.new@example.com', $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_confirming_marks_the_new_address_as_verified(): void
    {
        Notification::fake();
        // A typo at sign-up: the account was never verified.
        $user = User::factory()->unverified()->create(['email' => 'maya@exmaple.com']);
        $path = $this->linkFor($user, 'maya@example.com');

        $this->postJson($path)->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_link_is_refused_and_changes_nothing(): void
    {
        Notification::fake();
        $victim = $this->maya();
        $attacker = User::factory()->create();

        // The attacker points their own valid link at the victim's account.
        $path = $this->linkFor($attacker, 'evil@example.com');
        $victim->forceFill(['pending_email' => 'evil@example.com'])->save();
        $path = str_replace("/change/{$attacker->id}/", "/change/{$victim->id}/", $path);

        $this->postJson($path)->assertForbidden();

        $this->assertSame('maya@example.com', $victim->fresh()->email);
    }

    public function test_an_expired_link_is_refused_and_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->maya();
        $path = $this->linkFor($user, 'maya.new@example.com');

        $this->travel(61)->minutes();

        $this->postJson($path)->assertForbidden();
        $this->assertSame('maya@example.com', $user->fresh()->email);
    }

    public function test_an_older_link_stops_working_after_a_newer_request(): void
    {
        Notification::fake();
        $user = $this->maya();
        $oldPath = $this->linkFor($user, 'first@example.com');
        $this->requestChange($user, 'second@example.com')->assertAccepted();

        $this->postJson($oldPath)->assertForbidden();

        $user->refresh();
        $this->assertSame('maya@example.com', $user->email);
        $this->assertSame('second@example.com', $user->pending_email);
    }

    public function test_a_link_is_refused_after_the_change_was_cancelled(): void
    {
        Notification::fake();
        $user = $this->maya();
        $path = $this->linkFor($user, 'maya.new@example.com');
        $this->actingAs($user)->deleteJson('/api/user/email-change')->assertOk();

        $this->postJson($path)->assertForbidden();
        $this->assertSame('maya@example.com', $user->fresh()->email);
    }

    public function test_a_used_link_cannot_be_used_again(): void
    {
        Notification::fake();
        $user = $this->maya();
        $path = $this->linkFor($user, 'maya.new@example.com');
        $this->postJson($path)->assertOk();

        $this->postJson($path)->assertForbidden();
        $this->assertSame('maya.new@example.com', $user->fresh()->email);
    }

    public function test_an_address_registered_in_between_is_refused_at_swap_time(): void
    {
        Notification::fake();
        $user = $this->maya();
        $path = $this->linkFor($user, 'maya.new@example.com');
        User::factory()->create(['email' => 'maya.new@example.com']);

        $this->postJson($path)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'email_taken');

        $user->refresh();
        $this->assertSame('maya@example.com', $user->email);
        $this->assertSame('maya.new@example.com', $user->pending_email);
        Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 0);
    }

    public function test_the_old_address_is_notified_after_the_swap(): void
    {
        Notification::fake();
        $user = $this->maya();
        $path = $this->linkFor($user, 'maya.new@example.com');
        Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 0);

        $this->postJson($path)->assertOk();

        Notification::assertSentOnDemand(
            EmailChangedNotification::class,
            fn (EmailChangedNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'maya@example.com'
                && $notification->newEmail === 'maya.new@example.com',
        );
    }

    public function test_reset_tokens_for_the_old_address_are_deleted(): void
    {
        Notification::fake();
        $user = $this->maya();
        $this->postJson('/api/forgot-password', ['email' => 'maya@example.com']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'maya@example.com']);
        $path = $this->linkFor($user, 'maya.new@example.com');

        $this->postJson($path)->assertOk();

        // Otherwise the old link could reset the password of whoever signs up
        // with the old address next.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'maya@example.com']);
    }

    public function test_the_new_address_can_log_in_and_the_old_one_cannot(): void
    {
        Notification::fake();
        $user = $this->maya();
        $path = $this->linkFor($user, 'maya.new@example.com');
        $this->postJson($path)->assertOk();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['email' => 'maya@example.com', 'password' => 'password'])
            ->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'maya.new@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    // --- Database safety net -------------------------------------------------

    public function test_the_database_rejects_a_pending_address_equal_to_the_current_one(): void
    {
        $user = $this->maya();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_pending_email_differs');

        DB::table('users')->where('id', $user->id)->update(['pending_email' => 'maya@example.com']);
    }
}
