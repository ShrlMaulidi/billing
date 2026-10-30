<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menu global (tunnel, template isolir, akun user, hak akses) tidak terikat cabang, jadi hanya untuk user
 * semua cabang. Mencegah user cabang yang diberi permission menu ini melihat/mengubah data cabang lain.
 */
class EnsureUserCoversAllBranches
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->id_lokasi !== null, 403, 'Anda tidak memiliki akses ke fitur ini');

        return $next($request);
    }
}
