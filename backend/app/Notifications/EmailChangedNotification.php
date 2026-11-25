<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
| Sent to the OLD address once the swap is done (D20).
|
| This is the safety net against a stolen session: if an attacker managed
| to move the account to their own inbox, the real owner hears about it at
| the address they still read. On-demand, because after the swap the user
| model no longer knows the old address.
*/
class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $newEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your e-mail address was changed')
            ->line("The e-mail address of your Lebanon Tourism account was changed to {$this->newEmail}.")
            ->line('From now on, sign in with the new address. This address no longer receives account e-mails.')
            ->line('If you did not make this change, contact us right away: someone may have access to your account.');
    }
}
