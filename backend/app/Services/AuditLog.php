<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/*
| Writes the admin audit log (Exercise 12, D25).
|
| Callers record an event explicitly, INSIDE the DB::transaction that makes
| the change, after every rule has passed:
| - a refused action (BusinessRuleException, 404, 422) never reaches here,
|   so it leaves no event;
| - a crash after record() rolls back the change and its event together;
| - an event can never describe a change that did not commit.
|
| Callers also skip record() when nothing changed (suspending a user who is
| already suspended), so two admins clicking at once leave one event.
|
| Why not Eloquent observers? They would have to guess the actor from
| auth(), would fire for seeders and operator edits too, and cannot see
| actions that are not a single model save (session deletes, ledger rows).
*/
final class AuditLog
{
    /** @param array<string, mixed>|null $changes only what the action changed; never secrets or copied personal data */
    public function record(User $actor, AuditAction $action, Model $subject, ?array $changes = null): AuditEvent
    {
        $event = new AuditEvent;
        $event->forceFill([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'changes' => $changes,
        ]);
        $event->subject()->associate($subject);
        $event->save();

        return $event;
    }

    /**
     * One entry of `changes`: {"from": ..., "to": ...}. Enums are stored by value.
     *
     * @return array{from: mixed, to: mixed}
     */
    public static function change(mixed $from, mixed $to): array
    {
        return [
            'from' => $from instanceof BackedEnum ? $from->value : $from,
            'to' => $to instanceof BackedEnum ? $to->value : $to,
        ];
    }
}
