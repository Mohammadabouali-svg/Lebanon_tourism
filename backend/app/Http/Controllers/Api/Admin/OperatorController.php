<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OperatorStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\OperatorProfile;
use App\Models\User;
use App\Notifications\OperatorApprovedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/*
| Operator approval. Note that approve/suspend are separate, explicit POST
| endpoints. The original project used a single "toggle" triggered by a GET
| link, which any page could fire with an <img src="..."> tag (CSRF).
*/
class OperatorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OperatorStatus::class)],
        ]);

        $operators = User::query()
            ->where('role', UserRole::Operator)
            ->with(['operatorProfile', 'wallet'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->whereHas('operatorProfile', fn (Builder $profile) => $profile->where('status', $status)))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return UserResource::collection($operators);
    }

    public function approve(User $user): UserResource
    {
        $profile = $this->profileOf($user);
        $wasApprovedBefore = $profile->approved_at !== null;

        $profile->status = OperatorStatus::Approved;
        $profile->approved_at ??= now();
        $profile->save();

        // Re-approving after a suspension does not send the welcome e-mail again.
        if (! $wasApprovedBefore) {
            $user->notify(new OperatorApprovedNotification);
        }

        return UserResource::make($user->load(['operatorProfile', 'wallet']));
    }

    /** Their published tours disappear from the public site immediately (see Tour::visibleToPublic). */
    public function suspend(User $user): UserResource
    {
        $profile = $this->profileOf($user);
        $profile->status = OperatorStatus::Suspended;
        $profile->save();

        return UserResource::make($user->load(['operatorProfile', 'wallet']));
    }

    private function profileOf(User $user): OperatorProfile
    {
        abort_unless($user->isOperator() && $user->operatorProfile !== null, 404, 'This user is not a tour operator.');

        return $user->operatorProfile;
    }
}
