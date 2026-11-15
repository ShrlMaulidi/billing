<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Rekening;
use App\Services\Midtrans\MidtransClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cek tagihan pelanggan tanpa login (dari landing page) dengan ID pelanggan atau nomor HP terdaftar; hasilnya invoice
 * per bulan yang belum dibayar, sama seperti invoice di dashboard.
 * Halaman publik: hanya pelanggan aktif, data minimum (nama disamarkan, tanpa alamat/telepon), pencarian lewat POST
 * agar nomor HP tidak tercatat di URL/log, dan dibatasi throttle:cek-tagihan.
 * QR kartu member membuka kartu() lewat link bertanda tangan: ID di link tidak bisa diganti untuk melihat pelanggan lain.
 * Periode yang sudah lunas (cash, transfer, atau Midtrans) tampil di riwayat dan tidak bisa dibayar lagi; kwitansinya dibuka
 * lewat link bertanda tangan yang hanya dibuat untuk pembayaran milik pelanggan hasil pencarian.
 */
class CekTagihanController extends Controller
{
    public function __construct(private MidtransClient $midtrans) {}

    /** Batas pelanggan yang ditampilkan untuk satu nomor HP. */
    private const MAKS_HASIL = 5;

    /** Jumlah pembayaran terakhir di riwayat lunas (sama dengan rentang tagihan, 12 bulan). */
    private const MAKS_RIWAYAT = 12;

    /** Masa berlaku link kwitansi publik; setelah itu pelanggan cukup cek tagihan lagi untuk link baru. */
    private const JAM_LINK_KWITANSI = 24;

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

        return Inertia::render('CekTagihan/Index', ['cari' => $cari, 'hasil' => $this->hasil($this->cariPelanggan($cari))]);
    }

    /** Dibuka dari QR kartu member (link bertanda tangan, lihat KelolaDataController::kartuMember). Pelanggan nonaktif tampil "tidak ditemukan". */
    public function kartu(Pelanggan $pelanggan): Response
    {
        $hasil = $this->query()->whereKey($pelanggan->id)->get();

        return Inertia::render('CekTagihan/Index', ['cari' => $pelanggan->id_pelanggan ?? '', 'hasil' => $this->hasil($hasil)]);
    }

    /** Kwitansi publik dari riwayat lunas (link bertanda tangan). Data pribadi disamarkan; tanpa alamat, telepon, dan petugas. */
    public function kwitansi(Pembayaran $pembayaran): Response
    {
        $pembayaran->load(['pelanggan:id,id_pelanggan', 'lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi', 'rekening', 'transaksiMidtrans:id,pembayaran_id,payment_type']);

        return Inertia::render('CekTagihan/Kwitansi', [
            'kwitansi' => [
                ...$pembayaran->only(['no_invoice', 'periode', 'jns_tagihan', 'nama_paket', 'harga', 'is_prorate', 'diskon_tipe', 'diskon_nilai', 'diskon', 'jml_bayar', 'metode', 'paid_at']),
                'nama_pelanggan' => self::samarkan((string) $pembayaran->nama_pelanggan),
                'periode_pembayaran' => $pembayaran->periodePembayaran(),
                'id_pelanggan' => $pembayaran->pelanggan?->id_pelanggan,
                'cabang' => $pembayaran->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
                'rekening' => $pembayaran->rekening?->label(),
                'metode_label' => $pembayaran->keteranganMetode(),
            ],
        ]);
    }

    /** Link kwitansi publik yang tidak bisa diubah ke pembayaran lain dan kedaluwarsa setelah JAM_LINK_KWITANSI. */
    public static function linkKwitansi(Pembayaran $pembayaran): string
    {
        return URL::temporarySignedRoute('cek-tagihan.kwitansi', now()->addHours(self::JAM_LINK_KWITANSI), ['pembayaran' => $pembayaran->id], absolute: false);
    }

    /**
     * Data tagihan yang aman ditampilkan ke publik.
     *
     * @param  Collection<int, Pelanggan>  $pelanggan
     * @return Collection<int, array<string, mixed>>
     */
    private function hasil(Collection $pelanggan): Collection
    {
        return $pelanggan->map(fn (Pelanggan $p) => [
            'id_pelanggan' => $p->id_pelanggan,
            'nama' => self::samarkan($p->nama),
            'paket' => $p->paket?->nama_paket,
            'jns_tagihan' => $p->jns_tagihan,
            // Kantor cabang untuk kop invoice (data usaha, bukan data pribadi pelanggan)
            'cabang' => $p->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
            'tagihan' => $p->tagihanBelumDibayar(),
            // Riwayat lunas: periode ini tidak bisa dibayar lagi, kwitansi bisa dibuka
            'lunas' => $p->pembayaran->sortByDesc('periode')->take(self::MAKS_RIWAYAT)->map(fn (Pembayaran $b) => [
                'no_invoice' => $b->no_invoice,
                'periode' => $b->periode,
                'periode_pembayaran' => $b->periodePembayaran(),
                'paid_at' => $b->paid_at?->toDateString(),
                'jml_bayar' => $b->jml_bayar,
                'metode' => $b->metode,
                'metode_label' => $b->keteranganMetode(),
                'link_kwitansi' => self::linkKwitansi($b),
            ])->values(),
            // Bayar online lewat Midtrans (gateway perusahaan) hanya untuk pelanggan cabang non Admin Full
            'bayar_online' => $this->midtrans->aktif() && ! $p->lokasi?->admin_full,
            'rekening' => Rekening::where('tampil_invoice', true)->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $p->id_lokasi))
                ->orderBy('nama_rekening')->get()->map->only(['nama_rekening', 'no_rekening', 'atas_nama']),
        ])->values();
    }

    /** Pelanggan aktif beserta relasi yang dipakai hasil(). */
    private function query(): Builder
    {
        return Pelanggan::with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi,admin_full', 'pembayaran:id,pelanggan_id,no_invoice,periode,jns_tagihan,jml_bayar,metode,rekening_id,paid_at',
            'pembayaran.rekening:id,nama_rekening', 'pembayaran.transaksiMidtrans:id,pembayaran_id,payment_type'])
            ->where('status', 'aktif');
    }

    /**
     * ID pelanggan dicocokkan persis; bila tidak ada dan input berupa nomor HP, dicocokkan dengan nomor HP
     * terdaftar setelah dinormalkan (0812…, 62812…, +62 812-… dianggap sama).
     *
     * @return Collection<int, Pelanggan>
     */
    private function cariPelanggan(string $cari): Collection
    {
        $query = fn () => $this->query();

        $perId = $query()->where('id_pelanggan', $cari)->get();
        if ($perId->isNotEmpty()) {
            return $perId;
        }

        $nomor = $this->nomorHp($cari);
        if (strlen($nomor) < 10) {
            return collect();
        }

        // Saring kasar di database dengan 9 digit terakhir (pemisah spasi/-/. dibuang), lalu cocokkan persis setelah dinormalkan
        return $query()->whereRaw("REPLACE(REPLACE(REPLACE(tlp, '-', ''), ' ', ''), '.', '') LIKE ?", ['%'.substr($nomor, -9)])->limit(50)->get()
            ->filter(fn (Pelanggan $p) => $this->nomorHp((string) $p->tlp) === $nomor)
            ->take(self::MAKS_HASIL)->values();
    }

    /** Nomor HP ke format 08…: buang selain angka, awalan 62 diganti 0. */
    private function nomorHp(string $tlp): string
    {
        $angka = preg_replace('/\D/', '', $tlp);

        return str_starts_with($angka, '62') ? '0'.substr($angka, 2) : $angka;
    }

    /** "Budi Santoso" → "Bu** Sa****": cukup untuk dikenali pemiliknya tanpa membuka nama lengkap ke publik. */
    public static function samarkan(string $nama): string
    {
        return collect(preg_split('/\s+/', trim($nama)))
            ->map(fn (string $kata) => mb_substr($kata, 0, 2).str_repeat('*', max(1, mb_strlen($kata) - 2)))
            ->implode(' ');
    }
}
