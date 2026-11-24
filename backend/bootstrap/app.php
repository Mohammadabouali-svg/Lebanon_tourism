<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureOperatorIsApproved;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Lets the React app authenticate with Laravel's session cookie.
        // Requests coming from an origin listed in SANCTUM_STATEFUL_DOMAINS
        // get sessions, cookies and CSRF protection on the /api routes.
        $middleware->statefulApi();

        // Short names used in routes/api.php.
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'operator.approved' => EnsureOperatorIsApproved::class,
            'active' => EnsureAccountIsActive::class,
        ]);

        // Check who the user is BEFORE route model binding loads records from
        // the database. Otherwise a traveler calling /api/admin/users/999
        // would get "404 not found" (revealing which ids exist) instead of
        // "403 forbidden".
        foreach ([EnsureAccountIsActive::class, EnsureEmailIsVerified::class, EnsureUserHasRole::class, EnsureOperatorIsApproved::class] as $check) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $check);
        }

        // nginx (and, in development, the Vite proxy) sit in front of PHP.
        // Trusting the private Docker network lets Laravel read the visitor's
        // real IP from X-Forwarded-For, which rate limiting depends on.
        // Never trust '*' on a server reachable from the internet directly.
        $middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);

        // An API never redirects to a login page: guests get a 401 JSON answer.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error on /api/* is returned as JSON ({"message": "..."}),
        // never as an HTML error page. In production (APP_DEBUG=false) the
        // message of unexpected errors is a generic "Server Error": stack
        // traces, SQL and file paths stay in the log, out of the response.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
