<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;

/*
| Laravel's built-in verification e-mail, with two changes:
|
| 1. ShouldQueue: the e-mail is sent by the queue worker, so registering does
|    not wait for the SMTP server (and does not fail if it is briefly down).
|
| 2. The link points to the React app. The app reads the parameters and
|    calls POST /api/email/verify/{id}/{hash}?expires=...&signature=...
|
| The signature is an HMAC computed with APP_KEY over the URL path and query.
| Changing any character (another user id, a later expiry) invalidates it.
| That is what replaces the original project's guessable verification code.
*/
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    protected function verificationUrl($notifiable): string
    {
        // A *relative* signed URL (no host), because the API is reached
        // through different hosts in development (Vite proxy) and production.
        $signedPath = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
            absolute: false,
        );

        parse_str((string) parse_url($signedPath, PHP_URL_QUERY), $query);

        return config('app.frontend_url').'/verify-email?'.http_build_query([
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}
