<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\PajakCabang;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * Data pelanggan, cabang, tarif pajak, dan pembayaran diambil dari database. Setoran, pengeluaran, dan WAGW belum punya tabel,
 * sehingga nilainya kosong/0 sampai modul tersebut terhubung ke database.
 */
class DashboardController extends Controller
{
    /** Kolom rekap cabang yang menunggu modul tagihan, pembayaran, setoran, dan pengeluaran. */
    private const NOMINAL_REKAP = [
        'lunas', 'belum_lunas', 'pemasukan', 'pemasukan_via_mitra', 'total_cash', 'total_pg', 'dpp', 'ppn', 'admin_fee', 'nominal_bagi_hasil',
        'omset_reseller', 'pph', 'harus_setor', 'sudah_setor', 'sisa_setor', 'pendapatan_reseller', 'total_wd', 'sisa_wd', 'pengeluaran', 'saldo',
    ];

    public function index(Request $request)
    {
        $f = $this->filters($request);
        $today = today();
        $pelanggan = Pelanggan::query()->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']));
        $aktif = (clone $pelanggan)->where('status', 'aktif');
        $bulanIni = $today->format('Y-m-01');
        $pembayaran = Pembayaran::query()->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']));
        $awalGrafik = $today->copy()->startOfMonth()->subMonths(11);
        // Pemasukan per bulan berdasarkan tanggal bayar (paid_at)
        $pemasukanBulanan = (clone $pembayaran)->where('paid_at', '>=', $awalGrafik)->get(['paid_at', 'jml_bayar'])
            ->groupBy(fn (Pembayaran $p) => $p->paid_at->format('Y-m-01'))->map->sum('jml_bayar');

        return Inertia::render('Dashboard/Index', [
            'filters' => $f,
            'kpi' => [
                'total' => (clone $aktif)->count(),
                'lunas' => (clone $pelanggan)->lunas($bulanIni)->count(),
                'belum_lunas' => (clone $pelanggan)->belumLunas($bulanIni)->count(),
                'on' => (clone $aktif)->where('status_langganan', 'on')->count(),
                'off' => (clone $aktif)->where('status_langganan', 'off')->count(),
                'berhenti' => (clone $pelanggan)->where('status', 'berhenti')->count(),
                'pemasukan_bulan' => (int) $pemasukanBulanan->get($bulanIni, 0),
                'pemasukan_hari' => (int) (clone $pembayaran)->where('paid_at', '>=', $today->copy()->startOfDay())->sum('jml_bayar'),
            ],
            'pelangganBaru' => (clone $pelanggan)->with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi'])
                ->whereBetween('tgl_registrasi', $this->bulanIni())
                ->latest('tgl_registrasi')->take(8)->get()
                ->map->toBaris(),
            'wagw' => ['devices' => [], 'sent' => 0, 'outbox' => 0, 'failed' => 0],
            'grafik' => collect(range(11, 0))->map(fn ($i) => [
                'periode' => $periode = $today->copy()->startOfMonth()->subMonths($i)->format('Y-m-01'),
                'total' => (int) $pemasukanBulanan->get($periode, 0),
            ]),
        ]);
    }

    public function pelanggan(Request $request)
    {
        $f = $this->filters($request);
        $kategori = $request->input('kategori', 'total');
        $query = Pelanggan::with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi'])->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']));

        $bulanIni = today()->format('Y-m-01');
        $rows = match ($kategori) {
            'lunas' => $query->lunas($bulanIni)->orderBy('nama')->get(),
            'belum_lunas' => $query->belumLunas($bulanIni)->orderBy('nama')->get(),
            'berhenti' => $query->where('status', 'berhenti')->orderBy('nama')->get(),
            'baru' => $query->whereBetween('tgl_registrasi', $this->bulanIni())->latest('tgl_registrasi')->get(),
            default => $query->where('status', 'aktif')->orderBy('nama')->get(),
        };

        return Inertia::render('Dashboard/Pelanggan', ['filters' => $f + ['kategori' => $kategori], 'rows' => $rows->map->toBaris()]);
    }

    public function cabang(Request $request)
    {
        $f = $this->filters($request);

        return Inertia::render('DashboardCabang/Index', [
            'filters' => $f,
            'rows' => $this->rekapCabang(Lokasi::when($f['cabang'], fn ($q, $id) => $q->whereKey($id))->orderBy('kode_lokasi')->get(), $f),
        ]);
    }

    public function cabangDetail(Request $request, int $id)
    {
        $this->pastikanCabang($request, $id);
        $f = $this->filters($request);
        $lokasi = Lokasi::findOrFail($id);

        return Inertia::render('DashboardCabang/Detail', [
            'filters' => $f,
            'lokasi' => $lokasi->only(['id', 'kode_lokasi', 'nama_lokasi', 'alamat_lokasi', 'nama_mitra']),
            'rekap' => $this->rekapCabang($lokasi->newCollection([$lokasi]), $f)->first(),
            'pelanggan' => $lokasi->pelanggan()->with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi'])->orderBy('nama')->get()->map->toBaris(),
            'pembayaran' => [],
            'pengeluaran' => [],
        ]);
    }

    public function income(Request $request)
    {
        return Inertia::render('IncomeSummary/Index', [
            'filters' => $this->filters($request),
            'total' => ['total' => 0, 'jumlah' => 0],
            'metode' => collect(['cash', 'transfer', 'tripay', 'xendit', 'pakar_digital'])->map(fn ($m) => ['metode' => $m, 'total' => 0, 'jumlah' => 0]),
            'rekening' => [],
            'pembayaran' => [],
        ]);
    }

    /**
     * Rekap per cabang pada bulan filter: jumlah pelanggan dan tarif pajak dari database, nominal lain masih 0.
     *
     * @param  EloquentCollection<int, Lokasi>  $lokasi
     * @return Collection<int, array<string, mixed>>
     */
    private function rekapCabang(EloquentCollection $lokasi, array $f): Collection
    {
        $periode = $this->periode($f);
        $bulan = [$periode, Carbon::parse($periode)->endOfMonth()->toDateString()];
        $lokasi->loadCount([
            'pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif'),
            'pelanggan as pelanggan_baru' => fn ($q) => $q->whereBetween('tgl_registrasi', $bulan),
            'pelanggan as pelanggan_free' => fn ($q) => $q->where('status', 'aktif')->whereHas('paket', fn ($p) => $p->where('harga', 0)),
        ]);
        $tarif = PajakCabang::berlaku($lokasi->modelKeys(), $periode);

        return $lokasi->map(function (Lokasi $l) use ($tarif) {
            $t = $tarif->get($l->id);

            return [
                ...array_fill_keys(self::NOMINAL_REKAP, 0),
                'id_lokasi' => $l->id,
                'nama_cabang' => $l->nama_lokasi,
                'jumlah_pelanggan' => $l->jumlah_pelanggan,
                'pelanggan_baru' => $l->pelanggan_baru,
                'pelanggan_free' => $l->pelanggan_free,
                'ppn_persen' => $t?->ppn_persen,
                'pph_persen' => $t?->pph_persen,
                // Label "/trx" agar kartu ringkasan tidak menambahkan tanda %
                'admin_fee_label' => $t ? ($t->admin_fee_tipe === 'persentase' ? $t->admin_fee : 'Rp'.number_format($t->admin_fee, 0, ',', '.').'/trx') : null,
                'metode_bagi_hasil' => $t ? ($t->metode_bagi_hasil === 'persentase'
                    ? 'Persentase '.str_replace('.', ',', (string) $t->nilai_bagi_hasil).'%'
                    : 'Nominal Rp'.number_format($t->nilai_bagi_hasil, 0, ',', '.')) : null,
            ];
        })->values();
    }

    /**
     * Awal dan akhir bulan berjalan sebagai Y-m-d (kolom tgl_registrasi bertipe date).
     *
     * @return array{0: string, 1: string}
     */
    private function bulanIni(): array
    {
        return [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()];
    }
}
