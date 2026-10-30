<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hanya role tertentu yang boleh lewat, mis. ->middleware('role:Super Admin,Admin'). */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role, $roles, true)) {
            abort(403, 'Anda tidak memiliki akses ke fitur ini');
        }

        return $next($request);
    }
}
