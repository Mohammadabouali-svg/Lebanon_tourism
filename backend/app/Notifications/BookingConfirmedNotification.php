<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\User;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /*
    | The Booking model is serialized as just its id when the job is queued
    | and re-loaded from the database when the job runs. The e-mail therefore
    | shows the booking as it is at sending time.
    */
    public function __construct(public readonly Booking $booking) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing('tour');

        return (new MailMessage)
            ->subject("Booking {$booking->reference} confirmed")
            ->greeting("Thank you, {$notifiable->name}!")
            ->line("Your booking for \"{$booking->tour->title}\" is confirmed.")
            ->line("Departure: {$booking->tour->starts_on->toFormattedDayDateString()}")
            ->line("Travelers: {$booking->spots}")
            ->line('Total paid: '.Money::format($booking->total_cents))
            ->action('View my bookings', config('app.frontend_url').'/bookings');
    }
}
