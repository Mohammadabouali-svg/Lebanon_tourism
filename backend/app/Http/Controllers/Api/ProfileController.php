<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\WalletTransactionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /** GET /api/user: who is logged in? The React app calls this on start-up. */
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user()->load('operatorProfile', 'wallet'));
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->update($request->validated());

        return UserResource::make($user->load('operatorProfile', 'wallet'));
    }

    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        // Sign out every *other* browser where this account is logged in.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return response()->noContent();
    }

    /** The wallet balance and its most recent ledger entries. */
    public function wallet(Request $request): AnonymousResourceCollection
    {
        $wallet = $request->user()->wallet;

        return WalletTransactionResource::collection(
            $wallet->transactions()->latest('id')->paginate(20),
        )->additional(['balance_cents' => $wallet->balance_cents]);
    }
}
