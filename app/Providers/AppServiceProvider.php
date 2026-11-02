<?php

namespace App\Providers;

use App\Models\User;
use App\Support\HakAkses;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Di balik Cloudflare/proxy publik, header X-Forwarded-Proto tidak dipercaya sehingga request terlihat http.
        // Bila APP_URL https, semua URL & redirect dibuat https (berdasarkan konfigurasi server, bukan header dari luar).
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Super Admin lolos semua permission (spatie memeriksa permission lewat Gate)
        Gate::before(fn (User $user) => $user->hasRole(HakAkses::SUPER_ADMIN) ? true : null);

        // Batasi aksi yang menghubungi router agar akun yang dibobol/skrip tidak bisa membanjiri Mikrotik
        RateLimiter::for('mikrotik', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip())
            ->response(fn () => response()->json(['message' => 'Terlalu banyak permintaan ke router, coba lagi sebentar lagi'], 429)));
        // Cek tagihan publik (tanpa login): batasi per IP agar data pelanggan tidak bisa ditebak beruntun
        RateLimiter::for('cek-tagihan', fn (Request $request) => Limit::perMinute(10)->by($request->ip())
            ->response(fn () => back()->withErrors(['cari' => 'Terlalu banyak percobaan. Coba lagi dalam 1 menit.'])));
        // Login API (staf & pelanggan) per IP, di luar penguncian per akun di PercobaanLogin
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip())
            ->response(fn () => response()->json(['message' => 'Terlalu banyak percobaan login. Coba lagi dalam 1 menit.'], 429)));
    }
}
