<?php

namespace Tests\Feature;

use App\Enums\OperatorStatus;
use App\Models\Booking;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/*
| Who may call what. In the original project most admin pages had no check
| at all: an anonymous visitor could create an admin account with a single
| request. These tests pin the access rules down.
*/
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function adminEndpoints(): array
    {
        return [
            'create an admin' => ['POST', '/api/admin/admins'],
            'list users' => ['GET', '/api/admin/users'],
            'credit a wallet' => ['POST', '/api/admin/users/1/wallet-credits'],
            'approve an operator' => ['POST', '/api/admin/operators/1/approve'],
            'export all bookings' => ['GET', '/api/admin/bookings/export'],
            'create a discount' => ['POST', '/api/admin/discounts'],
            'delete a city' => ['DELETE', '/api/admin/cities/1'],
        ];
    }

    #[DataProvider('adminEndpoints')]
    public function test_guests_cannot_use_admin_endpoints(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('adminEndpoints')]
    public function test_travelers_and_operators_cannot_use_admin_endpoints(string $method, string $uri): void
    {
        $this->actingAs($this->traveler())->json($method, $uri)->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->json($method, $uri)->assertForbidden();
    }

    public function test_an_operator_waiting_for_approval_cannot_manage_tours(): void
    {
        $pending = User::factory()->operator(OperatorStatus::Pending)->create();

        $this->actingAs($pending)
            ->getJson('/api/operator/tours')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your operator account is waiting for approval by an administrator.');
    }

    public function test_an_operator_cannot_touch_another_operators_tour(): void
    {
        $tour = Tour::factory()->create();
        $otherOperator = User::factory()->operator()->create();

        $this->actingAs($otherOperator);
        $this->getJson("/api/operator/tours/{$tour->id}")->assertForbidden();
        $this->deleteJson("/api/operator/tours/{$tour->id}")->assertForbidden();

        $this->assertModelExists($tour);
    }

    public function test_operators_only_see_bookings_of_their_own_tours(): void
    {
        $operator = User::factory()->operator()->create();
        $own = Tour::factory()->for($operator, 'operator')->create();
        Booking::factory()->for($own)->create();
        Booking::factory()->create(); // someone else's tour

        $this->actingAs($operator)
            ->getJson('/api/operator/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_user_suspended_while_logged_in_is_signed_out(): void
    {
        $user = $this->traveler();
        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $user->forceFill(['suspended_at' => now()])->save();

        $this->getJson('/api/user')->assertForbidden();
    }

    public function test_guests_cannot_request_or_cancel_an_email_change(): void
    {
        $this->postJson('/api/user/email-change', ['email' => 'new@example.com', 'current_password' => 'password'])
            ->assertUnauthorized();
        $this->deleteJson('/api/user/email-change')->assertUnauthorized();
    }

    public function test_a_suspended_user_cannot_request_an_email_change(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user)
            ->postJson('/api/user/email-change', ['email' => 'new@example.com', 'current_password' => 'password'])
            ->assertForbidden();
        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_api_errors_are_json_never_html(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
