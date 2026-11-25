<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/*
| Releases the spots of card bookings stuck in `pending` (the server crashed
| between charging the card and confirming the booking). Scheduled every
| minute in routes/console.php; can also be run by hand:
|
|     docker compose exec php php artisan bookings:expire-pending
|
| The command is only the trigger. The rules (what "stale" means, locking,
| re-checking, releasing spots) live in BookingService::expireStalePending().
*/
#[Signature('bookings:expire-pending')]
#[Description('Mark card bookings stuck in pending as failed and release their spots')]
class ExpirePendingBookings extends Command
{
    public function handle(BookingService $bookings): int
    {
        $count = $bookings->expireStalePending();

        $this->info("Expired {$count} stale pending booking(s).");

        return self::SUCCESS;
    }
}
