<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One immutable line of a wallet's ledger. */
class WalletTransaction extends Model
{
    // The table has created_at but no updated_at: ledger rows never change.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** What caused this entry (a Booking), if anything. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
