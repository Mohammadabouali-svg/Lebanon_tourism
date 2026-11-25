<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/*
| Sent to the NEW address when a user asks to change their e-mail (D20).
|
| It is an "on-demand" notification (Notification::route('mail', $new)),
| because the user's `email` is still the old address: $user->notify() would
| mail the old inbox, and the point is to prove the new one works.
|
| The link copies the verification e-mail's design (VerifyEmailNotification):
| a RELATIVE temporary signed route, rewritten to a React page that calls
|     POST /api/email/change/{id}/{hash}?expires=...&signature=...
| The hash is sha1 of the pending address. A newer request changes the
| pending address, so every older link stops matching.
*/
class ConfirmEmailChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly string $newEmail,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Confirm your new e-mail address')
            ->line('Someone (hopefully you) asked to use this address for a Lebanon Tourism account.')
            ->line('Click the button to confirm. Until you do, the account keeps its current address.')
            ->action('Confirm this address', $this->confirmationUrl($minutes))
            ->line("The link expires in {$minutes} minutes. If you did not ask for this, ignore this e-mail.");
    }

    private function confirmationUrl(int $minutes): string
    {
        $hash = sha1($this->newEmail);

        $signedPath = URL::temporarySignedRoute(
            'email-change.confirm',
            now()->addMinutes($minutes),
            ['id' => $this->userId, 'hash' => $hash],
            absolute: false,
        );

        parse_str((string) parse_url($signedPath, PHP_URL_QUERY), $query);

        return config('app.frontend_url').'/confirm-email-change?'.http_build_query([
            'id' => $this->userId,
            'hash' => $hash,
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}
