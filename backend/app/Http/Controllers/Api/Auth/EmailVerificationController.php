<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /*
    | Reached through a link from the verification e-mail.
    |
    | The `signed:relative` route middleware has already checked the HMAC
    | signature and the expiry time before this method runs. The hash check
    | below additionally ties the link to the e-mail address it was sent to.
    |
    | The user does not need to be logged in: people often open the e-mail
    | on their phone while they registered on a laptop.
    */
    public function verify(int $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'This verification link is invalid.');

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your e-mail address is already verified.']);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['message' => 'Thank you, your e-mail address is verified.']);
    }

    public function resend(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your e-mail address is already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'A new verification link is on its way.'], 202);
    }
}
