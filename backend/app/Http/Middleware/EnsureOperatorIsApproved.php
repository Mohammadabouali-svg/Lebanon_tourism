<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
| Operators can log in as soon as they registered and verified their e-mail,
| but they may only manage tours after an admin approved their company.
*/
class EnsureOperatorIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->isApprovedOperator(),
            403,
            'Your operator account is waiting for approval by an administrator.',
        );

        return $next($request);
    }
}
