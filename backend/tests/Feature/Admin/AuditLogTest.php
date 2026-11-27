<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Enums\OperatorStatus;
use App\Models\AuditEvent;
use App\Models\Tour;
use App\Models\User;
use App\Notifications\OperatorApprovedNotification;
use App\Services\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/*
| The admin audit log (Exercise 12, D25): which admin actions leave an
| event, which do not, and that the table is append-only.
*/
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // --- The table itself --------------------------------------------------

    public function test_every_audit_action_is_accepted_by_the_database(): void
    {
        $subject = $this->traveler();

        foreach (AuditAction::cases() as $action) {
            app(AuditLog::class)->record($this->admin, $action, $subject);
        }

        $this->assertSame(count(AuditAction::cases()), AuditEvent::count());
    }

    public function test_the_database_refuses_an_unknown_audit_action(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('audit_events_action_valid');

        DB::table('audit_events')->insert([
            'actor_id' => $this->admin->id,
            'action' => 'user.deleted',
            'subject_type' => 'user',
            'subject_id' => $this->admin->id,
        ]);
    }

    public function test_the_database_refuses_to_update_an_audit_event(): void
    {
        $event = $this->anEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('audit_events')->where('id', $event->id)->update(['actor_id' => $this->traveler()->id]);
    }

    public function test_the_database_refuses_to_delete_an_audit_event(): void
    {
        $event = $this->anEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('audit_events')->where('id', $event->id)->delete();
    }

    public function test_the_database_refuses_to_truncate_the_audit_log(): void
    {
        $this->anEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::statement('TRUNCATE audit_events');
    }

    // --- Accounts -----------------------------------------------------------

    public function test_suspending_a_user_records_who_did_it(): void
    {
        $traveler = $this->traveler();

        $this->actingAs($this->admin)->postJson("/api/admin/users/{$traveler->id}/suspend")->assertOk();

        $this->assertRecorded(AuditAction::UserSuspended, $traveler);
    }

    public function test_suspending_an_already_suspended_user_records_nothing(): void
    {
        $user = User::factory()->suspended()->create(['suspended_at' => now()->subDay()]);
        $suspendedAt = $user->fresh()->suspended_at;

        $this->actingAs($this->admin)->postJson("/api/admin/users/{$user->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.suspended', true);

        $this->assertSame(0, AuditEvent::count());
        $this->assertEquals($suspendedAt, $user->fresh()->suspended_at);
    }

    public function test_a_refused_self_suspension_records_nothing(): void
    {
        $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->admin->id}/suspend")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cannot_suspend_self');

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_unsuspending_a_user_records_who_did_it(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($this->admin)->postJson("/api/admin/users/{$user->id}/unsuspend")
            ->assertOk()
            ->assertJsonPath('data.suspended', false);

        $this->assertRecorded(AuditAction::UserUnsuspended, $user);
    }

    public function test_unsuspending_an_active_user_records_nothing(): void
    {
        $traveler = $this->traveler();

        $this->actingAs($this->admin)->postJson("/api/admin/users/{$traveler->id}/unsuspend")->assertOk();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_creating_an_admin_records_who_did_it_without_any_secret(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/admins', ['name' => 'Second Admin', 'email' => 'second@example.com'])
            ->assertCreated();

        $event = $this->assertRecorded(AuditAction::AdminCreated, User::firstWhere('email', 'second@example.com'));
        $this->assertNull($event->changes);
    }

    public function test_an_invalid_admin_creation_records_nothing(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/admins', ['name' => 'Copy', 'email' => $this->admin->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, AuditEvent::count());
    }

    // --- Operators ----------------------------------------------------------

    public function test_approving_an_operator_records_the_status_change(): void
    {
        Notification::fake();
        $operator = User::factory()->operator(OperatorStatus::Pending)->create();

        $this->actingAs($this->admin)->postJson("/api/admin/operators/{$operator->id}/approve")->assertOk();

        $event = $this->assertRecorded(AuditAction::OperatorApproved, $operator);
        $this->assertEquals(['status' => ['from' => 'pending', 'to' => 'approved']], $event->changes);
        Notification::assertSentTo($operator, OperatorApprovedNotification::class);
    }

    public function test_suspending_an_operator_records_the_status_change(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($this->admin)->postJson("/api/admin/operators/{$operator->id}/suspend")->assertOk();

        $event = $this->assertRecorded(AuditAction::OperatorSuspended, $operator);
        $this->assertEquals(['status' => ['from' => 'approved', 'to' => 'suspended']], $event->changes);
    }

    public function test_approving_an_approved_operator_records_nothing_and_sends_nothing(): void
    {
        Notification::fake();
        $operator = User::factory()->operator()->create();

        $this->actingAs($this->admin)->postJson("/api/admin/operators/{$operator->id}/approve")->assertOk();

        $this->assertSame(0, AuditEvent::count());
        Notification::assertNothingSent();
    }

    public function test_approving_a_user_who_is_not_an_operator_records_nothing(): void
    {
        $traveler = $this->traveler();

        $this->actingAs($this->admin)->postJson("/api/admin/operators/{$traveler->id}/approve")->assertNotFound();

        $this->assertSame(0, AuditEvent::count());
    }

    // --- Wallet credits -----------------------------------------------------

    public function test_a_wallet_credit_records_the_amount_and_the_ledger_entry(): void
    {
        $traveler = $this->traveler();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$traveler->id}/wallet-credits", ['amount_cents' => 5_000, 'description' => 'Refund for the bus'])
            ->assertCreated();

        $event = $this->assertRecorded(AuditAction::WalletCredited, $traveler);
        $entry = $traveler->wallet->transactions()->sole();
        $this->assertEquals(['amount_cents' => 5_000, 'wallet_transaction_id' => $entry->id], $event->changes);
    }

    public function test_an_invalid_wallet_credit_records_nothing(): void
    {
        $traveler = $this->traveler();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$traveler->id}/wallet-credits", ['amount_cents' => 2_000_000, 'description' => 'Too much'])
            ->assertUnprocessable();

        $this->assertSame(0, AuditEvent::count());
    }

    // --- Tour status --------------------------------------------------------

    public function test_archiving_a_tour_records_the_status_and_the_lock(): void
    {
        $tour = Tour::factory()->create();

        $this->actingAs($this->admin)->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'archived'])->assertOk();

        $event = $this->assertRecorded(AuditAction::TourStatusChanged, $tour);
        $this->assertEquals([
            'status' => ['from' => 'published', 'to' => 'archived'],
            'locked' => ['from' => false, 'to' => true],
        ], $event->changes);
    }

    public function test_locking_a_tour_the_operator_archived_records_only_the_lock(): void
    {
        $tour = Tour::factory()->create(['status' => 'archived']);

        $this->actingAs($this->admin)->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'archived'])->assertOk();

        $event = $this->assertRecorded(AuditAction::TourStatusChanged, $tour);
        $this->assertEquals(['locked' => ['from' => false, 'to' => true]], $event->changes);
    }

    public function test_setting_a_tour_to_its_current_status_records_nothing(): void
    {
        $tour = Tour::factory()->create();

        $this->actingAs($this->admin)->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'published'])->assertOk();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_an_invalid_tour_status_records_nothing(): void
    {
        $tour = Tour::factory()->create();

        $this->actingAs($this->admin)->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'deleted'])->assertUnprocessable();

        $this->assertSame(0, AuditEvent::count());
    }

    // --- Reading the log (GET /api/admin/audit-events) -----------------------

    public function test_admins_see_the_audit_log_newest_first_with_current_names(): void
    {
        $traveler = $this->traveler();
        $tour = Tour::factory()->create(['title' => 'Byblos by night']);
        $this->actingAs($this->admin);
        $this->postJson("/api/admin/users/{$traveler->id}/suspend")->assertOk();
        $this->patchJson("/api/admin/tours/{$tour->id}/status", ['status' => 'archived'])->assertOk();

        $this->getJson('/api/admin/audit-events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'tour.status_changed')
            ->assertJsonPath('data.0.subject', ['type' => 'tour', 'id' => $tour->id, 'label' => 'Byblos by night'])
            ->assertJsonPath('data.0.changes.status.from', 'published')
            ->assertJsonPath('data.0.changes.status.to', 'archived')
            ->assertJsonPath('data.0.actor.id', $this->admin->id)
            ->assertJsonPath('data.0.actor.name', $this->admin->name)
            ->assertJsonPath('data.1.action', 'user.suspended')
            ->assertJsonPath('data.1.subject.label', $traveler->name);
    }

    public function test_the_audit_log_can_be_filtered_by_action_actor_and_subject(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $first = $this->traveler();
        $second = $this->traveler();
        $audit = app(AuditLog::class);
        $audit->record($this->admin, AuditAction::UserSuspended, $first);
        $audit->record($this->admin, AuditAction::UserUnsuspended, $first);
        $audit->record($otherAdmin, AuditAction::UserSuspended, $second);
        $this->actingAs($this->admin);

        $this->getJson('/api/admin/audit-events?action=user.suspended')->assertJsonPath('meta.total', 2);
        $this->getJson("/api/admin/audit-events?actor_id={$otherAdmin->id}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject.id', $second->id);
        $this->getJson("/api/admin/audit-events?subject_type=user&subject_id={$first->id}")->assertJsonPath('meta.total', 2);
        $this->getJson("/api/admin/audit-events?action=user.suspended&subject_type=user&subject_id={$first->id}")->assertJsonPath('meta.total', 1);
    }

    public function test_invalid_audit_log_filters_are_refused(): void
    {
        $this->actingAs($this->admin);

        $this->getJson('/api/admin/audit-events?action=user.deleted')->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->getJson('/api/admin/audit-events?subject_type=booking&subject_id=1')->assertUnprocessable()->assertJsonValidationErrors('subject_type');
        $this->getJson('/api/admin/audit-events?subject_id=1')->assertUnprocessable()->assertJsonValidationErrors('subject_type');
    }

    public function test_an_event_about_a_deleted_tour_is_kept_without_a_label(): void
    {
        $tour = Tour::factory()->create();
        app(AuditLog::class)->record($this->admin, AuditAction::TourStatusChanged, $tour, ['status' => AuditLog::change('published', 'archived')]);
        $tour->delete();

        $this->actingAs($this->admin)
            ->getJson('/api/admin/audit-events')
            ->assertOk()
            ->assertJsonPath('data.0.subject', ['type' => 'tour', 'id' => $tour->id, 'label' => null]);
    }

    public function test_unverified_and_suspended_admins_cannot_read_the_audit_log(): void
    {
        $this->actingAs(User::factory()->admin()->unverified()->create())->getJson('/api/admin/audit-events')->assertForbidden();
        $this->actingAs(User::factory()->admin()->suspended()->create())->getJson('/api/admin/audit-events')->assertForbidden();
    }

    private function anEvent(): AuditEvent
    {
        return app(AuditLog::class)->record($this->admin, AuditAction::UserSuspended, $this->traveler());
    }

    /** The one event written, by this test's admin, for this action and subject. */
    private function assertRecorded(AuditAction $action, Model $subject): AuditEvent
    {
        $event = AuditEvent::sole();
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame($action, $event->action);
        $this->assertSame($subject->getMorphClass(), $event->subject_type);
        $this->assertSame($subject->getKey(), $event->subject_id);

        return $event;
    }
}
