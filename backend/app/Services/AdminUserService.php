<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\OperatorStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\OperatorProfile;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\OperatorApprovedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/*
| The admin actions on accounts: create admins, suspend and unsuspend
| users, approve and suspend operators, credit wallets (Exercise 12, D25).
|
| They used to be a save() in the controller. Each now runs in one
| transaction that also writes its audit event (AuditLog), so the change
| and its record commit together or not at all.
|
| Each action locks the row it changes and reads the "before" value under
| that lock. Two admins acting at once therefore run one after the other;
| the second sees the change already made, changes nothing, and records
| nothing, so the log has no duplicates and no "from" that was never true.
|
| E-mails go out after the transaction (invariant 23): the queue is not
| `after_commit`, so a job dispatched inside could run before the commit,
| or be sent for a change that then rolled back.
*/
final class AdminUserService
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly WalletService $wallets,
    ) {}

    /*
    | The new admin gets a random password nobody knows and an e-mail with a
    | "choose your password" link (the normal reset flow), so the creating
    | admin never knows, types or sends someone else's password.
    */
    public function createAdmin(User $actor, string $name, string $email): User
    {
        $admin = DB::transaction(function () use ($actor, $name, $email): User {
            $admin = new User([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(32),
            ]);
            $admin->role = UserRole::Admin;
            $admin->email_verified_at = now();
            $admin->save();

            $this->audit->record($actor, AuditAction::AdminCreated, $admin);

            return $admin;
        });

        Password::sendResetLink(['email' => $admin->email]);

        return $admin;
    }

    /** @throws BusinessRuleException cannot_suspend_self */
    public function suspend(User $actor, User $user): User
    {
        if ($user->is($actor)) {
            throw BusinessRuleException::cannotSuspendSelf();
        }

        return DB::transaction(function () use ($actor, $user): User {
            $locked = $this->lockUser($user);
            if ($locked->isSuspended()) {
                return $locked; // already suspended: nothing changed, nothing to record
            }

            $locked->forceFill(['suspended_at' => now()])->save();
            // End their sessions right away (see also EnsureAccountIsActive).
            DB::table('sessions')->where('user_id', $locked->id)->delete();

            $this->audit->record($actor, AuditAction::UserSuspended, $locked);

            return $locked;
        });
    }

    public function unsuspend(User $actor, User $user): User
    {
        return DB::transaction(function () use ($actor, $user): User {
            $locked = $this->lockUser($user);
            if (! $locked->isSuspended()) {
                return $locked;
            }

            $locked->forceFill(['suspended_at' => null])->save();

            $this->audit->record($actor, AuditAction::UserUnsuspended, $locked);

            return $locked;
        });
    }

    /** The welcome e-mail goes out only the first time an operator is approved. */
    public function approveOperator(User $actor, User $operator): void
    {
        $firstApproval = DB::transaction(function () use ($actor, $operator): bool {
            $profile = $this->lockProfile($operator);
            $from = $profile->status;
            if ($from === OperatorStatus::Approved) {
                return false;
            }

            $firstApproval = $profile->approved_at === null;
            $profile->status = OperatorStatus::Approved;
            $profile->approved_at ??= now();
            $profile->save();

            $this->audit->record($actor, AuditAction::OperatorApproved, $operator, [
                'status' => AuditLog::change($from, OperatorStatus::Approved),
            ]);

            return $firstApproval;
        });

        if ($firstApproval) {
            $operator->notify(new OperatorApprovedNotification);
        }
    }

    /** Their published tours disappear from the public site immediately (see Tour::visibleToPublic). */
    public function suspendOperator(User $actor, User $operator): void
    {
        DB::transaction(function () use ($actor, $operator): void {
            $profile = $this->lockProfile($operator);
            $from = $profile->status;
            if ($from === OperatorStatus::Suspended) {
                return;
            }

            $profile->status = OperatorStatus::Suspended;
            $profile->save();

            $this->audit->record($actor, AuditAction::OperatorSuspended, $operator, [
                'status' => AuditLog::change($from, OperatorStatus::Suspended),
            ]);
        });
    }

    /*
    | The ledger row (created_by_id = the admin) stays the source of truth
    | for money. The audit event points at it instead of copying the
    | admin's description, so the free text lives in one place.
    */
    public function creditWallet(User $actor, User $user, int $amountCents, string $description): WalletTransaction
    {
        return DB::transaction(function () use ($actor, $user, $amountCents, $description): WalletTransaction {
            // credit() opens its own transaction; inside this one it is a savepoint.
            $transaction = $this->wallets->credit($user->wallet, $amountCents, $description, createdBy: $actor);

            $this->audit->record($actor, AuditAction::WalletCredited, $user, [
                'amount_cents' => $amountCents,
                'wallet_transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    private function lockUser(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockProfile(User $operator): OperatorProfile
    {
        return OperatorProfile::query()->where('user_id', $operator->getKey())->lockForUpdate()->firstOrFail();
    }
}
