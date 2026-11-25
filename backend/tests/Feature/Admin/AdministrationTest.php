<?php

namespace Tests\Feature\Admin;

use App\Enums\OperatorStatus;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Tour;
use App\Models\User;
use App\Notifications\OperatorApprovedNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_wallet_credit_is_recorded_in_the_ledger_with_the_admin(): void
    {
        $traveler = $this->traveler(balanceCents: 1_000);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$traveler->id}/wallet-credits", [
                'amount_cents' => 5_000,
                'description' => 'Compensation for the cancelled bus',
            ])
            ->assertCreated()
            ->assertJsonPath('balance_cents', 6_000);

        $entry = $traveler->wallet->transactions()->sole();
        $this->assertSame($this->admin->id, $entry->created_by_id);
        $this->assertSame('Compensation for the cancelled bus', $entry->description);
    }

    public function test_wallet_credit_amounts_are_bounded(): void
    {
        $traveler = $this->traveler();
        $this->actingAs($this->admin);

        foreach ([-500, 0, 99, 2_000_000] as $amount) {
            $this->postJson("/api/admin/users/{$traveler->id}/wallet-credits", ['amount_cents' => $amount, 'description' => 'x'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount_cents');
        }

        $this->assertSame(0, $traveler->wallet->fresh()->balance_cents);
    }

    public function test_a_new_admin_is_invited_by_email_instead_of_receiving_a_password(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/admins', ['name' => 'Second Admin', 'email' => 'second@example.com', 'password' => 'ignored'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'admin');

        $newAdmin = User::firstWhere('email', 'second@example.com');
        $this->assertSame(UserRole::Admin, $newAdmin->role);
        Notification::assertSentTo($newAdmin, ResetPasswordNotification::class);
    }

    public function test_suspending_a_user_ends_their_sessions(): void
    {
        $traveler = $this->traveler();
        DB::table('sessions')->insert(['id' => 'their-session', 'user_id' => $traveler->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->postJson("/api/admin/users/{$traveler->id}/suspend")->assertOk()->assertJsonPath('data.suspended', true);

        $this->assertDatabaseMissing('sessions', ['id' => 'their-session']);
    }

    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->admin->id}/suspend")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cannot_suspend_self');
    }

    public function test_approving_an_operator_notifies_them_once(): void
    {
        Notification::fake();
        $operator = User::factory()->operator(OperatorStatus::Pending)->create();
        $this->actingAs($this->admin);

        $this->postJson("/api/admin/operators/{$operator->id}/approve")->assertOk()->assertJsonPath('data.operator.status', 'approved');
        $this->postJson("/api/admin/operators/{$operator->id}/suspend")->assertOk();
        $this->postJson("/api/admin/operators/{$operator->id}/approve")->assertOk();

        Notification::assertSentToTimes($operator, OperatorApprovedNotification::class, 1);
    }

    public function test_a_city_used_by_tours_cannot_be_deleted(): void
    {
        $tour = Tour::factory()->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/cities/{$tour->city_id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'still_in_use');

        $this->assertModelExists(City::find($tour->city_id));
    }

    public function test_admins_can_archive_any_tour(): void
    {
        $tour = Tour::factory()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->getJson("/api/tours/{$tour->id}")->assertNotFound();
    }

    // --- Moderation lock (Exercise 6, D19) ------------------------------------

    public function test_archiving_a_tour_locks_it_against_operator_changes(): void
    {
        $tour = Tour::factory()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.moderated_at', fn (?string $value) => $value !== null);

        $this->assertNotNull($tour->fresh()->moderated_at);
    }

    public function test_publishing_an_archived_tour_removes_the_lock(): void
    {
        $tour = Tour::factory()->moderated()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.moderated_at', null);

        $this->assertNull($tour->fresh()->moderated_at);
    }

    public function test_publishing_a_tour_does_not_lock_it(): void
    {
        $tour = Tour::factory()->draft()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'published'])
            ->assertOk();

        $this->assertNull($tour->fresh()->moderated_at);
    }

    public function test_the_lock_is_shown_in_the_admin_tour_list(): void
    {
        $tour = Tour::factory()->moderated()->create();

        $this->actingAs($this->admin)
            ->getJson('/api/admin/tours')
            ->assertOk()
            ->assertJsonPath('data.0.moderated_at', $tour->moderated_at->toIso8601String());
    }

    public function test_the_database_refuses_a_moderated_tour_that_is_not_archived(): void
    {
        // Even code that bypasses TourService (tinker, a future import)
        // cannot leave a locked tour published: tours_moderated_means_archived.
        $tour = Tour::factory()->moderated()->create();

        $this->expectException(QueryException::class);
        DB::table('tours')->where('id', $tour->id)->update(['status' => 'published']);
    }

    public function test_dashboard_aggregates_confirmed_bookings(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonCount(12, 'data.monthly')
            ->assertJsonPath('data.users.admins', 1);
    }
}
