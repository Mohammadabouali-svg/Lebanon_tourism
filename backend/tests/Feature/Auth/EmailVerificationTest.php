<?php

namespace Tests\Feature\Auth;

use App\Models\Departure;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Builds the API path the React app calls from the link in the e-mail. */
    private function verificationPathFor(User $user): string
    {
        $mailUrl = (new VerifyEmailNotification)->toMail($user)->actionUrl;
        parse_str((string) parse_url($mailUrl, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith('http://localhost:5173/verify-email?', $mailUrl);

        return "/api/email/verify/{$params['id']}/{$params['hash']}?".http_build_query([
            'expires' => $params['expires'],
            'signature' => $params['signature'],
        ]);
    }

    public function test_the_signed_link_verifies_the_email_address(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson($this->verificationPathFor($user))->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $victim = User::factory()->unverified()->create();
        $attacker = User::factory()->unverified()->create();

        // Take the attacker's valid link and point it at the victim's account.
        $path = str_replace("/verify/{$attacker->id}/", "/verify/{$victim->id}/", $this->verificationPathFor($attacker));

        $this->postJson($path)->assertForbidden();
        $this->assertFalse($victim->fresh()->hasVerifiedEmail());
    }

    public function test_an_unverified_traveler_cannot_book(): void
    {
        $user = User::factory()->unverified()->create();
        $departure = Departure::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/departures/{$departure->id}/bookings", ['spots' => 1, 'payment_method' => 'wallet'])
            ->assertForbidden();

        $this->assertSame(0, $departure->fresh()->booked_spots);
    }
}
