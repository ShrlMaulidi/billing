<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tiket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard pelanggan (role Pelanggan, prefix /akun-saya, tampilan template Cuba): Home berisi tagihan + Bayar Sekarang,
 * Riwayat Tagihan, Tiket (PortalTiketController), dan Akun. Data hanya milik pelanggan yang memakai akun ini
 * (pelanggan.user_id); tagihan memakai data yang sama dengan Cek Tagihan, dengan nama tampil lengkap.
 */
class PortalPelangganController extends Controller
{
    /** Batas baris riwayat pembayaran (±5 tahun langganan bulanan untuk beberapa langganan). */
    private const MAKS_RIWAYAT = 120;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $pelanggan = CekTagihanController::query()->where('user_id', $user->id)->orderBy('nama')->get();

        return Inertia::render('Portal/Home', [
            'hasil' => CekTagihanController::hasil($pelanggan, samarkanNama: false),
            'tiketAktif' => Tiket::where('user_id', $user->id)->where('status', '!=', 'selesai')->count(),
        ]);
    }

    /** Semua pembayaran lunas milik pelanggan akun ini (termasuk langganan yang sudah berhenti), terbaru dulu. */
    public function riwayat(Request $request): Response
    {
        $pelanggan = Pelanggan::where('user_id', $request->user()->id)->orderBy('nama')->get(['id', 'id_pelanggan', 'nama']);
        $rows = Pembayaran::with(['rekening:id,nama_rekening', 'transaksiMidtrans:id,pembayaran_id,payment_type'])
            ->whereIn('pelanggan_id', $pelanggan->pluck('id'))
            ->orderByDesc('periode')->orderByDesc('id')->limit(self::MAKS_RIWAYAT)->get()
            ->map(fn (Pembayaran $b) => [
                ...CekTagihanController::barisLunas($b),
                'nama_paket' => $b->nama_paket,
                'pelanggan' => $pelanggan->firstWhere('id', $b->pelanggan_id)?->only(['id_pelanggan', 'nama']),
            ]);

        return Inertia::render('Portal/Riwayat', ['rows' => $rows, 'jumlahLangganan' => $pelanggan->count()]);
    }

    /** Data akun & langganan, ganti password, dan pilihan navigasi di HP. */
    public function akun(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Portal/Akun', [
            'akun' => ['nama' => $user->name, 'username' => $user->username, 'last_login_at' => $user->last_login_at?->toDateTimeString()],
            'langganan' => Pelanggan::with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi'])->where('user_id', $user->id)->orderBy('nama')->get()
                ->map(fn (Pelanggan $p) => [
                    'id_pelanggan' => $p->id_pelanggan, 'nama' => $p->nama, 'alamat' => $p->alamat, 'status' => $p->status,
                    'paket' => $p->paket?->nama_paket, 'harga' => $p->paket?->harga, 'cabang' => $p->lokasi?->nama_lokasi,
                    'jns_tagihan' => $p->jns_tagihan, 'tgl_jatuh_tempo' => $p->tgl_jatuh_tempo, 'tgl_registrasi' => $p->tgl_registrasi?->toDateString(),
                ]),
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
