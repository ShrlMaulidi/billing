<?php

namespace App\Http\Controllers;

use App\Support\HakAkses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    /** Maksimal percobaan gagal untuk kombinasi username + IP, lalu dikunci selama JEDA_DETIK. */
    private const MAX_PERCOBAAN = 5;

    private const JEDA_DETIK = 300;

    /** Maksimal percobaan gagal dari satu IP untuk username apa pun (mencegah menebak banyak akun sekaligus). */
    private const MAX_PERCOBAAN_IP = 20;

    private const JEDA_IP_DETIK = 900;

    public function create()
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ], $this->pesanValidasi(), ['username' => 'Username atau email', 'password' => 'Kata sandi']);

        $key = Str::lower($data['username']).'|'.$request->ip();
        $keyIp = 'login-ip|'.$request->ip();
        foreach ([$key => self::MAX_PERCOBAAN, $keyIp => self::MAX_PERCOBAAN_IP] as $kunci => $maks) {
            if (RateLimiter::tooManyAttempts($kunci, $maks)) {
                throw ValidationException::withMessages([
                    'username' => 'Terlalu banyak percobaan login. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.',
                ]);
            }
        }

        // Kolom login menerima username maupun email
        $kolom = filter_var($data['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        if (! Auth::attempt([$kolom => $data['username'], 'password' => $data['password']], $data['remember'] ?? false)) {
            RateLimiter::hit($key, self::JEDA_DETIK);
            RateLimiter::hit($keyIp, self::JEDA_IP_DETIK);
            Log::warning('Login gagal', ['username' => $data['username'], 'ip' => $request->ip(), 'user_agent' => $request->userAgent()]);
            throw ValidationException::withMessages(['username' => 'Username/email atau kata sandi salah.']);
        }

        // Akun nonaktif, atau role-nya belum diberi satu menu pun di Hak Akses
        $user = $request->user();
        if (! $user->bolehMasuk()) {
            Log::warning('Login ditolak: akun nonaktif / tanpa hak akses', ['username' => $data['username'], 'status' => $user->status, 'ip' => $request->ip()]);
            Auth::logout();
            throw ValidationException::withMessages(['username' => $user->status !== 'aktif'
                ? 'Akun ini sedang dinonaktifkan. Hubungi Super Admin.'
                : 'Akun ini belum memiliki akses ke aplikasi. Hubungi Super Admin.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return redirect()->intended(HakAkses::halamanAwal($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
