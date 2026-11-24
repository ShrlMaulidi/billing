<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman informasi pelanggan di /pelanggan (link yang dibagikan ke pelanggan), terbuka tanpa login: cek tagihan dengan
 * nomor HP / ID pelanggan, hubungi CS, daftar baru, lapor gangguan, cara bayar, paket, dan tombol masuk ke akun pelanggan.
 * Staf yang sedang login dengan akses Data Pelanggan dialihkan ke menu Data Pelanggan (/pelanggan/data).
 */
class InfoPelangganController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($this->stafDataPelanggan($request)) {
            return redirect()->route('pelanggan.index', $request->query());
        }

        return Inertia::render('InfoPelanggan/Index', $this->props($request, ['cari' => '', 'hasil' => null]));
    }

    /** Cek tagihan; data & aturan sama dengan halaman Cek Tagihan (nama disamarkan, dibatasi throttle:cek-tagihan). */
    public function cari(Request $request): Response
    {
        return Inertia::render('InfoPelanggan/Index', $this->props($request, CekTagihanController::cariDari($request)));
    }

    /** Muat ulang halaman setelah cek tagihan (URL hasil POST) kembali ke halaman awal. */
    public function kembali(): RedirectResponse
    {
        return redirect()->route('info-pelanggan');
    }

    private function props(Request $request, array $pencarian): array
    {
        return [
            ...$pencarian,
            'paket' => LandingController::paketPublik(),
            // Pelanggan yang sudah login: tombol Masuk menjadi Buka Dashboard
            'masuk' => $request->user()?->isPelanggan() ? '/akun-saya' : null,
        ];
    }

    private function stafDataPelanggan(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && ! $user->isPelanggan() && $user->can('pelanggan.data.view');
    }
}
