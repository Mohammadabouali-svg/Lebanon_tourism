<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/*
| Laravel's password reset e-mail, linking to the React app's reset page.
|
| The token in the link is random (64 characters from a cryptographically
| secure generator). The database only stores a hash of it, and it expires
| after 60 minutes (config/auth.php → passwords.users.expire).
*/
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    protected function resetUrl($notifiable): string
    {
        return config('app.frontend_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
