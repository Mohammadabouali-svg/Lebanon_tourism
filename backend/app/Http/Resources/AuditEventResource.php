<?php

namespace App\Http\Resources;

use App\Models\AuditEvent;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/*
| One audit event for the admin page (Exercise 12, D25).
|
| Names, e-mails and titles are not stored in the event: they are read
| from the current actor and subject rows here, so the log holds no
| second copy of personal data. `subject.label` is null when the subject
| no longer exists (an operator deleted the tour).
|
| @mixin AuditEvent
*/
class AuditEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ],
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => match (true) {
                    $this->subject instanceof User => $this->subject->name,
                    $this->subject instanceof Tour => $this->subject->title,
                    default => null,
                },
            ],
            'changes' => $this->changes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
