<?php

namespace App\Http\Controllers;

use App\Models\Paket;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman pertama website (company profile PT Media Solusi Sukses), terbuka tanpa login.
 * Isi profil ada di halaman React; dari database hanya data publik: paket yang ditandai tampil di registrasi.
 */
class LandingController extends Controller
{
    /** Jumlah paket maksimal yang ditampilkan di landing page. */
    private const MAKS_PAKET = 6;

    public function __invoke(): Response
    {
        return Inertia::render('Landing/Index', [
            // Paket bernama sama di beberapa cabang cukup tampil sekali
            'paket' => Paket::where('tampil_registrasi', true)->where('harga', '>', 0)->orderBy('harga')->get(['nama_paket', 'harga', 'rate_limit', 'jns_koneksi', 'keterangan'])
                ->unique('nama_paket')->take(self::MAKS_PAKET)->values()
                ->map(fn (Paket $p) => [
                    'nama' => $p->nama_paket,
                    'harga' => $p->harga,
                    // Rate limit Mikrotik "upload/download", mis. "10M/20M" → kecepatan download "20 Mbps"
                    'kecepatan' => $this->kecepatan($p->rate_limit),
                    'koneksi' => $p->jns_koneksi,
                    'keterangan' => $p->keterangan,
                ]),
        ]);
    }

    private function kecepatan(?string $rateLimit): ?string
    {
        $download = explode('/', (string) $rateLimit)[1] ?? explode('/', (string) $rateLimit)[0];
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([kKmMgG])?$/', trim($download), $m)) {
            return null;
        }

        return $m[1].' '.match (strtoupper($m[2] ?? '')) {
            'K' => 'Kbps', 'G' => 'Gbps', default => 'Mbps'
        };
    }
}
