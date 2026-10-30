<?php

namespace App\Providers;

use App\Models\User;
use App\Support\HakAkses;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        // Super Admin lolos semua permission (spatie memeriksa permission lewat Gate)
        Gate::before(fn (User $user) => $user->hasRole(HakAkses::SUPER_ADMIN) ? true : null);

        // Batasi aksi yang menghubungi router agar akun yang dibobol/skrip tidak bisa membanjiri Mikrotik
        RateLimiter::for('mikrotik', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip())
            ->response(fn () => response()->json(['message' => 'Terlalu banyak permintaan ke router, coba lagi sebentar lagi'], 429)));
    }
}
