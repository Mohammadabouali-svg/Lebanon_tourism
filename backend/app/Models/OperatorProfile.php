<?php

namespace App\Models;

use App\Enums\OperatorStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// `status` is not fillable: only admin actions change it, explicitly.
#[Fillable(['company_name', 'business_phone'])]
class OperatorProfile extends Model
{
    use HasFactory;

    /** In-memory defaults, mirroring the database (see User::$attributes). */
    protected $attributes = [
        'status' => 'pending',
        'approved_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => OperatorStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
