<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Akun Saya untuk pelanggan yang login (role Pelanggan): tagihan, bayar online, dan riwayat pembayaran
 * semua langganan aktif bernomor HP akun ini, memakai data yang sama dengan Cek Tagihan (nama tampil lengkap).
 */
class PortalPelangganController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $pelanggan = CekTagihanController::query()->where('user_id', $user->id)->orderBy('nama')->get();

        return Inertia::render('Portal/Index', [
            'akun' => ['nama' => $user->name, 'username' => $user->username],
            'hasil' => CekTagihanController::hasil($pelanggan, samarkanNama: false),
        ]);
    }

    /** Halaman wajib buat password baru setelah login pertama / password di-reset (sebelum bisa membuka Akun Saya). */
    public function passwordBaru(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->wajib_ganti_password) {
            return redirect('/akun-saya');
        }

        return Inertia::render('Portal/PasswordBaru', ['akun' => ['nama' => $request->user()->name, 'username' => $request->user()->username]]);
    }

    /**
     * Ganti password; sesi lain akun ini dikeluarkan. Password lama tidak ditanya saat wajib membuat password baru
     * (baru saja login dengan password awal), selain itu wajib dikonfirmasi.
     */
    public function gantiPassword(Request $request): JsonResponse
    {
        $wajib = $request->user()->wajib_ganti_password;
        $data = $request->validate([
            'password_lama' => [$wajib ? 'nullable' : 'required', 'string', ...($wajib ? [] : ['current_password'])],
            'password' => ['required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed', 'different:password_lama'],
        ], [
            ...$this->pesanValidasi(),
            'current_password' => 'Password lama salah',
            'password.min' => 'Password minimal 8 karakter',
            'password.regex' => 'Password harus berisi huruf dan angka',
            'password.confirmed' => 'Konfirmasi password tidak sama',
            'password.different' => 'Password baru harus berbeda dari password lama',
        ], ['password_lama' => 'Password lama', 'password' => 'Password baru']);

        $user = $request->user();
        $user->forceFill(['password' => $data['password'], 'wajib_ganti_password' => false, 'remember_token' => Str::random(60)])->save();
        // Sesi ini tetap masuk, sesi di perangkat lain harus login ulang
        $request->session()->regenerate();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }

        return response()->json(['message' => 'Password berhasil diganti']);
    }
}
