<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\OperatorStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterOperatorRequest;
use App\Http\Requests\Auth\RegisterTravelerRequest;
use App\Http\Resources\UserResource;
use App\Models\OperatorProfile;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
| Both registration flows end the same way: the new user is logged in and
| receives a verification e-mail. Until they click the link, the routes
| marked `verified` (booking, reviewing, managing tours) answer 403.
*/
class RegistrationController extends Controller
{
    public function traveler(RegisterTravelerRequest $request): JsonResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'password', 'phone']));
        // Never taken from the request: public sign-up always creates travelers.
        $user->role = UserRole::Traveler;
        $user->save();

        return $this->finish($request, $user);
    }

    public function operator(RegisterOperatorRequest $request): JsonResponse
    {
        // User + profile in one transaction: no half-registered operators.
        $user = DB::transaction(function () use ($request) {
            $user = new User($request->safe()->only(['name', 'email', 'password', 'phone']));
            $user->role = UserRole::Operator;
            $user->save();

            $profile = new OperatorProfile($request->safe()->only(['company_name', 'business_phone']));
            $profile->status = OperatorStatus::Pending;
            $user->operatorProfile()->save($profile);

            return $user;
        });

        return $this->finish($request, $user);
    }

    private function finish(Request $request, User $user): JsonResponse
    {
        // Laravel listens for this event and sends the verification e-mail.
        event(new Registered($user));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return UserResource::make($user->load('operatorProfile', 'wallet'))
            ->response()
            ->setStatusCode(201);
    }
}
