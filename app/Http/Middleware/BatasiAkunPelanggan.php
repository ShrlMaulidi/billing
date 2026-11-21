<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun pelanggan (password awal = nomor HP) hanya boleh membuka halaman Akun Saya, logout, dan halaman publik;
 * selama belum membuat password baru, hanya halaman buat password baru & logout.
 * Semua halaman lain yang butuh login ditolak walau role Pelanggan keliru diberi permission menu staf
 * (permission spatie lolos lebih dulu sebelum Gate::before milik aplikasi, jadi pembatasan dilakukan di sini).
 */
class BatasiAkunPelanggan
{
    /** Route ber-login yang boleh dibuka akun pelanggan. */
    private const ROUTE_BOLEH = ['akun-saya', 'akun-saya.password-baru', 'akun-saya.password', 'logout'];

    /** Route yang masih boleh dibuka selama pelanggan belum membuat password baru. */
    private const ROUTE_PASSWORD_AWAL = ['akun-saya.password-baru', 'akun-saya.password', 'logout'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $user = $request->user();
        if (! $route || ! $user?->isPelanggan() || ! in_array('auth', $route->gatherMiddleware(), true)) {
            return $next($request);
        }

        // Masih memakai password awal (nomor HP): wajib membuat password baru dulu
        if ($user->wajib_ganti_password && ! in_array($route->getName(), self::ROUTE_PASSWORD_AWAL, true)) {
            abort_if($request->expectsJson() || ! $request->isMethod('GET'), 403, 'Buat password baru terlebih dahulu');

            return redirect('/akun-saya/password-baru');
        }
        if (! in_array($route->getName(), self::ROUTE_BOLEH, true)) {
            abort_if($request->expectsJson() || ! $request->isMethod('GET'), 403, 'Anda tidak memiliki akses ke fitur ini');

            return redirect('/akun-saya');
        }

        return $next($request);
    }
}
