<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
| Route middleware: `->middleware('role:admin')` or `role:operator,admin`.
|
| Applied to a whole route *group* in routes/api.php, so a new admin endpoint
| is protected the moment it is added to the group. Nobody has to remember
| to paste an `if (! admin) exit;` at the top of every file.
*/
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(UserRole::from(...), $roles);

        abort_unless($request->user()?->hasRole(...$allowed), 403, 'You are not allowed to access this area.');

        return $next($request);
    }
}
