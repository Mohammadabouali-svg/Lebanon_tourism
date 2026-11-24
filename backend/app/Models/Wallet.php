<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
| The balance is never mass-assignable and never written directly by
| controllers. Every change goes through App\Services\WalletService, which
| locks the row, updates the balance and writes a ledger entry atomically.
*/
class Wallet extends Model
{
    /** In-memory default, mirroring the database (see User::$attributes). */
    protected $attributes = ['balance_cents' => 0];

    protected function casts(): array
    {
        return ['balance_cents' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
