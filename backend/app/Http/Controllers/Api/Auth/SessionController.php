<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/*
| Logging in creates a *session*: Laravel stores the user id server-side and
| gives the browser an HttpOnly cookie that identifies the session.
|
| Why not a token in localStorage? JavaScript can read localStorage, so any
| XSS bug would let an attacker steal the token. HttpOnly cookies are
| invisible to JavaScript. CSRF (the classic weakness of cookies) is covered
| by Sanctum's XSRF-TOKEN check. See docs/03-authentication.md.
*/
class SessionController extends Controller
{
    public function store(LoginRequest $request): UserResource
    {
        $request->authenticate();

        $user = $request->user();

        if ($user->isSuspended()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages(['email' => 'This account has been suspended.']);
        }

        // A fresh session id after login defeats "session fixation": an
        // attacker who planted a session id before login gains nothing.
        $request->session()->regenerate();

        return UserResource::make($user->load('operatorProfile', 'wallet'));
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        // Throw away the session data and the CSRF token tied to it.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
