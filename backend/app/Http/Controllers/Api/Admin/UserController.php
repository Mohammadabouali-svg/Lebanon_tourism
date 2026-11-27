<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreditWalletRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\User;
use App\Services\AdminUserService;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/*
| User administration. Every route here sits behind `role:admin`.
|
| Accounts are suspended rather than deleted: deleting a user would also
| have to delete (or orphan) their bookings, payments and ledger entries,
| which the business needs for accounting and disputes.
*/
class UserController extends Controller
{
    public function __construct(private readonly AdminUserService $admins) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $users = User::query()
            ->with(['wallet', 'operatorProfile'])
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->when($filters['search'] ?? null, function (Builder $query, string $term) {
                $pattern = Search::containsPattern($term);
                $query->where(fn (Builder $q) => $q->whereLike('name', $pattern)->orWhereLike('email', $pattern));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return UserResource::collection($users);
    }

    /** Creates another administrator, invited by e-mail (see AdminUserService::createAdmin). */
    public function storeAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
        ]);

        $admin = $this->admins->createAdmin($request->user(), name: $data['name'], email: $data['email']);

        return UserResource::make($admin->load('wallet'))->response()->setStatusCode(201);
    }

    /** Also ends their sessions. Audited (D25). */
    public function suspend(Request $request, User $user): UserResource
    {
        $user = $this->admins->suspend($request->user(), $user);

        return UserResource::make($user->load(['wallet', 'operatorProfile']));
    }

    public function unsuspend(Request $request, User $user): UserResource
    {
        $user = $this->admins->unsuspend($request->user(), $user);

        return UserResource::make($user->load(['wallet', 'operatorProfile']));
    }

    /** Adds credit to a user's wallet. Recorded in the ledger with the admin's id, and audited (D25). */
    public function creditWallet(CreditWalletRequest $request, User $user): JsonResponse
    {
        $transaction = $this->admins->creditWallet(
            $request->user(),
            $user,
            amountCents: $request->integer('amount_cents'),
            description: $request->validated('description'),
        );

        return WalletTransactionResource::make($transaction)
            ->additional(['balance_cents' => $transaction->balance_after_cents])
            ->response()
            ->setStatusCode(201);
    }
}
