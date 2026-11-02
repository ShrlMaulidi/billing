<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Rekening;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cek tagihan pelanggan tanpa login (dari landing page) dengan ID pelanggan atau nomor HP terdaftar; hasilnya invoice
 * per bulan yang belum dibayar, sama seperti invoice di dashboard.
 * Halaman publik: hanya pelanggan aktif, data minimum (nama disamarkan, tanpa alamat/telepon), pencarian lewat POST
 * agar nomor HP tidak tercatat di URL/log, dan dibatasi throttle:cek-tagihan.
 */
class CekTagihanController extends Controller
{
    /** Batas pelanggan yang ditampilkan untuk satu nomor HP. */
    private const MAKS_HASIL = 5;

    public function index(): Response
    {
        return Inertia::render('CekTagihan/Index', ['hasil' => null]);
    }

    public function cari(Request $request): Response
    {
        $data = $request->validate(
            ['cari' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9+\-. ]+$/']],
            ['cari.required' => 'Masukkan ID pelanggan atau nomor HP', 'cari.min' => 'Minimal 3 karakter', 'cari.max' => 'Maksimal 30 karakter', 'cari.regex' => 'Hanya huruf, angka, spasi, +, -, dan titik'],
        );
        $cari = trim($data['cari']);

        $pelanggan = $this->cariPelanggan($cari);

        return Inertia::render('CekTagihan/Index', [
            'cari' => $cari,
            'hasil' => $pelanggan->map(fn (Pelanggan $p) => [
                'id_pelanggan' => $p->id_pelanggan,
                'nama' => $this->samarkan($p->nama),
                'paket' => $p->paket?->nama_paket,
                'jns_tagihan' => $p->jns_tagihan,
                // Kantor cabang untuk kop invoice (data usaha, bukan data pribadi pelanggan)
                'cabang' => $p->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
                'tagihan' => $p->tagihanBelumDibayar(),
                'pembayaran_terakhir' => ($bayar = $p->pembayaran->sortByDesc('periode')->first()) ? [
                    'periode_pembayaran' => $bayar->periodePembayaran(),
                    'paid_at' => $bayar->paid_at?->toDateString(),
                ] : null,
                'rekening' => Rekening::where('tampil_invoice', true)->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $p->id_lokasi))
                    ->orderBy('nama_rekening')->get()->map->only(['nama_rekening', 'no_rekening', 'atas_nama']),
            ])->values(),
        ]);
    }

    /**
     * ID pelanggan dicocokkan persis; bila tidak ada dan input berupa nomor HP, dicocokkan dengan nomor HP
     * terdaftar setelah dinormalkan (0812…, 62812…, +62 812-… dianggap sama).
     *
     * @return Collection<int, Pelanggan>
     */
    private function cariPelanggan(string $cari): Collection
    {
        $query = fn () => Pelanggan::with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi', 'pembayaran:id,pelanggan_id,periode,jns_tagihan,paid_at'])
            ->where('status', 'aktif');

        $perId = $query()->where('id_pelanggan', $cari)->get();
        if ($perId->isNotEmpty()) {
            return $perId;
        }

        $nomor = Pelanggan::nomorHp($cari);
        if (strlen($nomor) < 10) {
            return collect();
        }

        // Saring kasar di database dengan 9 digit terakhir (pemisah spasi/-/. dibuang), lalu cocokkan persis setelah dinormalkan
        return $query()->whereRaw("REPLACE(REPLACE(REPLACE(tlp, '-', ''), ' ', ''), '.', '') LIKE ?", ['%'.substr($nomor, -9)])->limit(50)->get()
            ->filter(fn (Pelanggan $p) => Pelanggan::nomorHp((string) $p->tlp) === $nomor)
            ->take(self::MAKS_HASIL)->values();
    }

    /** "Budi Santoso" → "Bu** Sa****": cukup untuk dikenali pemiliknya tanpa membuka nama lengkap ke publik. */
    private function samarkan(string $nama): string
    {
        return collect(preg_split('/\s+/', trim($nama)))
            ->map(fn (string $kata) => mb_substr($kata, 0, 2).str_repeat('*', max(1, mb_strlen($kata) - 2)))
            ->implode(' ');
    }
}
