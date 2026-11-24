<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreditWalletRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\User;
use App\Services\WalletService;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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

    /*
    | Creates another administrator.
    |
    | The new admin gets a random password nobody knows and an e-mail with a
    | "choose your password" link (the normal reset flow). The creating admin
    | therefore never knows, types or sends someone else's password.
    */
    public function storeAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
        ]);

        $admin = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Str::password(32),
        ]);
        $admin->role = UserRole::Admin;
        $admin->email_verified_at = now();
        $admin->save();

        Password::sendResetLink(['email' => $admin->email]);

        return UserResource::make($admin->load('wallet'))->response()->setStatusCode(201);
    }

    public function suspend(Request $request, User $user): UserResource
    {
        if ($user->is($request->user())) {
            throw BusinessRuleException::cannotSuspendSelf();
        }

        $user->forceFill(['suspended_at' => now()])->save();
        // End their sessions right away (see also EnsureAccountIsActive).
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return UserResource::make($user->load(['wallet', 'operatorProfile']));
    }

    public function unsuspend(User $user): UserResource
    {
        $user->forceFill(['suspended_at' => null])->save();

        return UserResource::make($user->load(['wallet', 'operatorProfile']));
    }

    /** Adds credit to a user's wallet. Recorded in the ledger with the admin's id. */
    public function creditWallet(CreditWalletRequest $request, User $user, WalletService $wallets): JsonResponse
    {
        $transaction = $wallets->credit(
            $user->wallet,
            $request->integer('amount_cents'),
            $request->validated('description'),
            createdBy: $request->user(),
        );

        return WalletTransactionResource::make($transaction)
            ->additional(['balance_cents' => $transaction->balance_after_cents])
            ->response()
            ->setStatusCode(201);
    }
}
