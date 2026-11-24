<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\User;
use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

/*
| Application-wide wiring and defaults. Runs on every request, so keep it
| to configuration: no database queries here.
*/
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // "When someone asks for a PaymentGateway, give them ...".
        // BookingService just declares PaymentGateway in its constructor and
        // Laravel's container injects whatever is bound here.
        $this->app->bind(PaymentGateway::class, fn () => match (config('payments.gateway')) {
            'fake' => new FakePaymentGateway,
            default => throw new InvalidArgumentException('Unknown payment gateway ['.config('payments.gateway').'].'),
        });
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configurePasswords();
        $this->configureRateLimits();

        // Refuse `migrate:fresh`, `db:wipe` etc. on the production database.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    private function configureModels(): void
    {
        // Outside production, Eloquent throws instead of silently:
        //  - lazy loading relations in a loop (the "N+1 queries" problem),
        //  - discarding attributes that are not fillable,
        //  - returning null for attributes that were not selected.
        // Bugs show up while developing instead of as slow pages in production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Dates cannot be modified in place: $date->addDay() returns a new
        // object, so a shared date can never change under your feet.
        Date::use(CarbonImmutable::class);

        // wallet_transactions.reference_type stores "booking", not the PHP
        // class name. Renaming or moving a class can't corrupt old rows.
        Relation::enforceMorphMap([
            'booking' => Booking::class,
            'user' => User::class,
        ]);
    }

    private function configurePasswords(): void
    {
        // In production, passwords must also not appear in known data breaches
        // (checked against haveibeenpwned.com; only the first 5 characters of
        // the password's SHA-1 hash are sent, never the password).
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(8));
    }

    /*
    | Named rate limits, used in routes/api.php as `throttle:<name>`.
    | Logged-in users are limited per account, guests per IP address.
    */
    private function configureRateLimits(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('quotes', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('bookings', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
    }
}
