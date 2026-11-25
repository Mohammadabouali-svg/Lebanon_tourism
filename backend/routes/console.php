<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled housekeeping. In production the `scheduler` container runs
| `php artisan schedule:work`, which executes these at the given times.
*/

// Remove expired password reset tokens.
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

// Release the spots of card bookings stuck in `pending` for over 15 minutes
// (Exercise 5). Every minute, so spots are held at most ~16 minutes.
// withoutOverlapping(): if one run is slow, the next one is skipped instead of
// running alongside it (the row locks would keep that safe anyway).
Schedule::command('bookings:expire-pending')->everyMinute()->withoutOverlapping();

// Remove failed queue jobs older than a week.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 7])->daily();
