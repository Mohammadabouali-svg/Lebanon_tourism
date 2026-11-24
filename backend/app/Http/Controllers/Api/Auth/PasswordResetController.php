<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/*
| Password reset, built on Laravel's "password broker". Compare with the
| original project, which could be bypassed entirely:
|
|   original                              here
|   ----------------------------------    -----------------------------------------
|   6-digit code from time() * rand()     64 random characters (CSPRNG)
|   stored in plain text                  only a hash is stored
|   never expires                         expires after 60 minutes
|   unlimited guesses                     throttled, single-use
|   reset page only checked a session     every reset checks the token itself
|     flag set *before* the code was
|     entered
*/
class PasswordResetController extends Controller
{
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        // The broker's result is deliberately ignored: the answer is the same
        // whether or not an account exists, so this endpoint cannot be used
        // to find out who is registered.
        Password::sendResetLink(['email' => Str::lower(trim($request->string('email')))]);

        return response()->json([
            'message' => 'If an account exists for this address, a reset link is on its way.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                'email' => Str::lower(trim($request->string('email'))),
                'password' => $request->string('password')->value(),
                'password_confirmation' => $request->string('password_confirmation')->value(),
                'token' => $request->string('token')->value(),
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password, // hashed by the model's 'hashed' cast
                    'remember_token' => Str::random(60),
                ])->save();

                // Clicking a link sent to the inbox proves the address works.
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }

                // Log the account out everywhere: if the password was reset
                // because it was stolen, the thief's session must end too.
                DB::table('sessions')->where('user_id', $user->id)->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // "Unknown e-mail" and "bad token" get the same answer, again to
            // avoid revealing which addresses have accounts.
            $message = $status === Password::RESET_THROTTLED
                ? __($status)
                : 'This password reset link is invalid or has expired.';

            throw ValidationException::withMessages(['email' => $message]);
        }

        return response()->json(['message' => 'Your password has been reset. You can now log in.']);
    }
}
