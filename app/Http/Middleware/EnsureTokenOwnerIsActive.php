<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasangan EnsureUserIsActive untuk API: token milik user yang dinonaktifkan, atau pelanggan yang sudah berhenti,
 * langsung dicabut sehingga aplikasi mobile harus login ulang.
 */
class EnsureTokenOwnerIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $pemilik = $request->user();
        if ($pemilik && $pemilik->status !== 'aktif') {
            $pemilik->currentAccessToken()?->delete();

            return response()->json(['message' => $pemilik instanceof User ? 'Akun Anda dinonaktifkan' : 'Layanan Anda sudah tidak aktif'], 401);
        }

        return $next($request);
    }
}
