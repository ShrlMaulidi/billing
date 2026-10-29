<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    /** Maksimal percobaan gagal per menit untuk kombinasi username + IP. */
    private const MAX_PERCOBAAN = 5;

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
        if (RateLimiter::tooManyAttempts($key, self::MAX_PERCOBAAN)) {
            throw ValidationException::withMessages([
                'username' => 'Terlalu banyak percobaan login. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        // Kolom login menerima username maupun email
        $kolom = filter_var($data['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        if (! Auth::attempt([$kolom => $data['username'], 'password' => $data['password']], $data['remember'] ?? false)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'Username/email atau kata sandi salah.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
