<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperatorApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        // MailMessage escapes every value it puts into the HTML template, so
        // a company name containing <script> is displayed, never executed.
        return (new MailMessage)
            ->subject('Your operator account has been approved')
            ->greeting("Welcome aboard, {$notifiable->name}!")
            ->line("{$notifiable->operatorProfile->company_name} can now publish tours on ".config('app.name').'.')
            ->action('Open your dashboard', config('app.frontend_url').'/operator');
    }
}
