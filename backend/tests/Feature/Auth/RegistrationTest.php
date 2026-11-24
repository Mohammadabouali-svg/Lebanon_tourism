<?php

namespace Tests\Feature\Auth;

use App\Enums\OperatorStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/*
| RefreshDatabase runs the migrations once, then wraps every test in a
| transaction that is rolled back afterwards. Each test starts from an empty
| database and tests cannot influence each other.
*/
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_register_as_a_traveler(): void
    {
        // Fake notifications: nothing is really sent, but we can assert what would be.
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Maya Khoury',
            'email' => '  Maya@Example.com ',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'traveler')
            ->assertJsonPath('data.email', 'maya@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'maya@example.com');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->wallet, 'Every user gets a wallet.');
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_the_client_cannot_choose_its_own_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
            'role' => 'admin',
        ])->assertCreated();

        $this->assertSame(UserRole::Traveler, User::firstWhere('email', 'sneaky@example.com')->role);
    }

    public function test_an_email_address_can_only_be_registered_once_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'maya@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Maya again',
            'email' => 'MAYA@example.com',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_passwords_must_be_confirmed_and_long_enough(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Short',
            'email' => 'short@example.com',
            'password' => 'abc',
            'password_confirmation' => 'abd',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_an_operator_registers_with_a_pending_company_profile(): void
    {
        Notification::fake();

        $this->postJson('/api/register/operator', [
            'name' => 'Elie Rahme',
            'email' => 'elie@cedars.example',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
            'company_name' => 'Cedars Trails',
            'business_phone' => '+961 3 123 456',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'operator')
            ->assertJsonPath('data.operator.status', 'pending');

        $operator = User::firstWhere('email', 'elie@cedars.example');
        $this->assertSame(OperatorStatus::Pending, $operator->operatorProfile->status);
        $this->assertFalse($operator->isApprovedOperator());
    }
}
