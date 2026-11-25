<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/*
| API Resources decide exactly which fields leave the server.
|
| Returning a model directly (`return $user;`) would send every column that
| is not hidden, including any column someone adds next year. A resource is
| an explicit allow-list: new columns stay private until listed here.
|
| `whenLoaded('relation')` includes a relation only if the controller
| eager-loaded it, so a resource never triggers extra queries on its own.
|
| @mixin User
*/
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'email_verified' => $this->email_verified_at !== null,
            // Shown only to the user themself (GET /api/user) and admins; it
            // is the address they asked to switch to (D20).
            'pending_email' => $this->pending_email,
            'suspended' => $this->isSuspended(),
            'operator' => $this->whenLoaded('operatorProfile', fn () => $this->operatorProfile ? [
                'company_name' => $this->operatorProfile->company_name,
                'business_phone' => $this->operatorProfile->business_phone,
                'status' => $this->operatorProfile->status,
                'approved_at' => $this->operatorProfile->approved_at?->toIso8601String(),
            ] : null),
            'wallet_balance_cents' => $this->whenLoaded('wallet', fn () => $this->wallet?->balance_cents),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
