<?php

namespace App\Http\Controllers;

use App\Support\HakAkses;
use App\Support\PercobaanLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
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

        $percobaan = new PercobaanLogin($data['username'], $request->ip());
        $percobaan->pastikanBelumTerkunci();

        // Kolom login menerima username maupun email
        $kolom = filter_var($data['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        if (! Auth::attempt([$kolom => $data['username'], 'password' => $data['password']], $data['remember'] ?? false)) {
            $percobaan->gagal();
            Log::warning('Login gagal', ['username' => $data['username'], 'ip' => $request->ip(), 'user_agent' => $request->userAgent()]);
            throw ValidationException::withMessages(['username' => 'Username/email atau kata sandi salah.']);
        }

        // Akun nonaktif, atau role-nya belum diberi satu menu pun di Hak Akses
        $user = $request->user();
        if (! $user->bolehMasuk()) {
            Log::warning('Login ditolak: akun nonaktif / tanpa hak akses', ['username' => $data['username'], 'status' => $user->status, 'ip' => $request->ip()]);
            Auth::logout();
            throw ValidationException::withMessages(['username' => $user->alasanTidakBolehMasuk()]);
        }

        $percobaan->berhasil();
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
