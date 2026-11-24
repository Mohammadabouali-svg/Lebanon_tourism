<?php

namespace App\Services;

use App\Enums\WalletTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/*
| The ONLY place that changes a wallet balance.
|
| The classic bug this class prevents is the "lost update":
|
|   Request A reads balance 100        Request B reads balance 100
|   A writes 100 - 80 = 20             B writes 100 - 80 = 20
|   → $160 spent, only $80 deducted.
|
| The fix is a row lock: `SELECT ... FOR UPDATE` (lockForUpdate()) makes the
| second request wait until the first transaction commits, and it then reads
| the *new* balance. The CHECK (balance_cents >= 0) constraint in the
| migration is a second safety net.
*/
final class WalletService
{
    public function credit(
        Wallet $wallet,
        int $amountCents,
        string $description,
        ?Model $reference = null,
        ?User $createdBy = null,
    ): WalletTransaction {
        return $this->apply($wallet, WalletTransactionType::Credit, $amountCents, $description, $reference, $createdBy);
    }

    /**
     * @throws BusinessRuleException when the balance is too low
     */
    public function debit(
        Wallet $wallet,
        int $amountCents,
        string $description,
        ?Model $reference = null,
    ): WalletTransaction {
        return $this->apply($wallet, WalletTransactionType::Debit, $amountCents, $description, $reference, null);
    }

    private function apply(
        Wallet $wallet,
        WalletTransactionType $type,
        int $amountCents,
        string $description,
        ?Model $reference,
        ?User $createdBy,
    ): WalletTransaction {
        // A programming error, not a user error: callers must validate amounts.
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Wallet amounts must be positive.');
        }

        // DB::transaction commits when the closure returns and rolls back if
        // it throws. When called inside another transaction (BookingService),
        // Laravel uses a savepoint, so the outer transaction stays in charge.
        return DB::transaction(function () use ($wallet, $type, $amountCents, $description, $reference, $createdBy) {
            /** @var Wallet $locked */
            $locked = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            $newBalance = $type === WalletTransactionType::Credit
                ? $locked->balance_cents + $amountCents
                : $locked->balance_cents - $amountCents;

            if ($newBalance < 0) {
                throw BusinessRuleException::insufficientFunds();
            }

            $locked->balance_cents = $newBalance;
            $locked->save();

            $transaction = new WalletTransaction;
            $transaction->forceFill([
                'type' => $type,
                'amount_cents' => $amountCents,
                'balance_after_cents' => $newBalance,
                'description' => $description,
                'created_by_id' => $createdBy?->getKey(),
            ]);
            $transaction->wallet()->associate($locked);
            if ($reference !== null) {
                $transaction->reference()->associate($reference);
            }
            $transaction->save();

            // Keep the caller's object in sync, so it does not show a stale balance.
            $wallet->balance_cents = $newBalance;

            return $transaction;
        });
    }
}
