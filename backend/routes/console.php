<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled housekeeping. In production the `scheduler` container runs
| `php artisan schedule:work`, which executes these at the given times.
*/

// Remove expired password reset tokens.
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

// Remove failed queue jobs older than a week.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 7])->daily();
