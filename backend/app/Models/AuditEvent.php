<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/*
| One line of the admin audit log (Exercise 12, D25).
|
| Written only by App\Services\AuditLog, inside the same transaction as the
| change it describes. Nothing is fillable: every column is set by that
| service with forceFill(). There is no update or delete code path, and the
| database refuses both anyway (trigger `audit_events_append_only`).
*/
class AuditEvent extends Model
{
    // The table has created_at but no updated_at: events never change.
    public const UPDATED_AT = null;

    /** In-memory default, mirroring the database (see User::$attributes). */
    protected $attributes = [
        'changes' => null,
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** The admin who acted. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** What the action was about (a User or a Tour); null if the tour was deleted since. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
