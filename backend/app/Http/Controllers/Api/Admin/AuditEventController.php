<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/*
| The admin audit log, read-only (Exercise 12, D25). Events are written by
| the services that make the changes (AuditLog); there is no endpoint that
| creates, edits or deletes one.
*/
class AuditEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            // "Everything about this user / tour": both or neither.
            'subject_type' => ['nullable', 'required_with:subject_id', Rule::in(['user', 'tour'])],
            'subject_id' => ['nullable', 'required_with:subject_type', 'integer', 'min:1'],
        ]);

        $events = AuditEvent::query()
            // morphTo eager loading runs one query per subject type. A deleted
            // tour simply comes back as a null subject.
            ->with(['actor', 'subject'])
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['actor_id'] ?? null, fn (Builder $query, string $actorId) => $query->where('actor_id', (int) $actorId))
            ->when($filters['subject_type'] ?? null, fn (Builder $query) => $query
                ->where('subject_type', $filters['subject_type'])
                ->where('subject_id', $filters['subject_id']))
            // id, not created_at: ids only grow, and every index ends with id.
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return AuditEventResource::collection($events);
    }
}
