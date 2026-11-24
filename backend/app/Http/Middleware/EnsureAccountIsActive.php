<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/*
| Login already refuses suspended accounts, but a user might be suspended
| while logged in. This middleware runs on every authenticated request and
| ends such sessions immediately instead of waiting for them to expire.
*/
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            abort(403, 'This account has been suspended.');
        }

        return $next($request);
    }
}
