<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Changing a user's e-mail address: verify first, swap second (D20).
|
| 1. request(): store the new address in `pending_email` and mail a signed
|    link TO it. `email` is untouched, so a typo cannot lock the user out.
| 2. confirm(): the link was clicked, which proves the user reads the new
|    inbox. Swap the addresses, then tell the OLD inbox what happened.
|
| `pending_email` is not fillable: only this class writes it (forceFill).
*/
final class EmailChangeService
{
    /*
    | A new request simply overwrites the previous pending address. That is
    | also how "resend the link" works, and it invalidates every older link
    | (their hash no longer matches).
    */
    public function request(User $user, string $newEmail): void
    {
        $user->forceFill(['pending_email' => $newEmail])->save();

        // Queued (ShouldQueue). No transaction is open here, so the e-mail
        // cannot announce a change that was rolled back.
        Notification::route('mail', $newEmail)
            ->notify(new ConfirmEmailChangeNotification($user->id, $newEmail));
    }

    /** Cancelling makes every link already sent unusable. */
    public function cancel(User $user): void
    {
        $user->forceFill(['pending_email' => null])->save();
    }

    /*
    | Called from the signed link. The `signed:relative` middleware has
    | already checked the signature and the expiry; this checks that the link
    | is for the address that is pending *now*, and that it is still free.
    |
    | Why each step:
    | - The row lock (inside the transaction, before reading pending_email)
    |   serialises this with a concurrent request()/cancel() of the same
    |   user, so we never swap to an address the user just replaced.
    | - The `exists()` check gives a clear `email_taken` answer when someone
    |   registered the address after it was requested. Two users confirming
    |   the same address at the same moment both pass it; the unique index on
    |   users.email then rejects the second one, which we report the same way.
    | - email_verified_at = now(): clicking the link proved the new address.
    | - Reset tokens are keyed by e-mail. A token for the old address would
    |   otherwise survive and, if someone signed up with the old address
    |   within the hour, reset THEIR password.
    |
    | Sessions are not touched: they belong to the user id, not the address,
    | and the password did not change.
    |
    | @throws AuthorizationException  the link is not for the pending address (403)
    | @throws BusinessRuleException   email_taken
    */
    public function confirm(int $userId, string $hash): string
    {
        [$oldEmail, $newEmail] = DB::transaction(function () use ($userId, $hash): array {
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            $pending = $user->pending_email;
            if ($pending === null || ! hash_equals(sha1($pending), $hash)) {
                throw new AuthorizationException('This link is invalid or was replaced by a newer request.');
            }

            if (User::query()->where('email', $pending)->exists()) {
                throw BusinessRuleException::emailTaken();
            }

            $oldEmail = $user->email;
            $user->forceFill([
                'email' => $pending,
                'pending_email' => null,
                'email_verified_at' => now(),
            ]);

            try {
                $user->save();
            } catch (UniqueConstraintViolationException) {
                throw BusinessRuleException::emailTaken();
            }

            DB::table('password_reset_tokens')->where('email', $oldEmail)->delete();

            return [$oldEmail, $pending];
        });

        // After commit (invariant 23): only a swap that really happened is announced.
        Notification::route('mail', $oldEmail)->notify(new EmailChangedNotification($newEmail));

        return $newEmail;
    }
}
