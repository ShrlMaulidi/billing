<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Support\RekapCabang;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Data pelanggan, cabang, tarif pajak, pembayaran, dan penarikan diambil dari database. Setoran, pengeluaran, dan WAGW belum punya tabel,
 * sehingga nilainya kosong/0 sampai modul tersebut terhubung ke database.
 */
class DashboardController extends Controller
{
    private const PAYMENT_GATEWAY = ['tripay', 'xendit', 'pakar_digital'];

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
            'saldoMitra' => $this->saldoMitra($request, $f),
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
            'rows' => RekapCabang::hitung(Lokasi::when($f['cabang'], fn ($q, $id) => $q->whereKey($id))->orderBy('kode_lokasi')->get(), $this->periode($f)),
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
            'rekap' => RekapCabang::hitung($lokasi->newCollection([$lokasi]), $this->periode($f))->first(),
            'pelanggan' => $lokasi->pelanggan()->with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi'])->orderBy('nama')->get()->map->toBaris(),
            'pembayaran' => [],
            'pengeluaran' => [],
        ]);
    }

    /** Pemasukan bulan filter (menurut tanggal bayar) per metode & rekening. User cabang (mis. Mitra) hanya melihat cabangnya sendiri. */
    public function income(Request $request)
    {
        $f = $this->filters($request);
        $periode = Carbon::parse($this->periode($f));
        $pembayaran = Pembayaran::with(['pelanggan:id,id_pelanggan', 'lokasi:id,nama_lokasi', 'rekening', 'petugas:id,name'])
            ->when($f['cabang'], fn ($q, $id) => $q->where('id_lokasi', $id))
            ->whereBetween('paid_at', [$periode->copy()->startOfDay(), $periode->copy()->endOfMonth()])
            ->latest('paid_at')->get();

        return Inertia::render('IncomeSummary/Index', [
            'filters' => $f,
            'total' => ['total' => (int) $pembayaran->sum('jml_bayar'), 'jumlah' => $pembayaran->count()],
            // Payment gateway belum punya pengaturan per cabang: kartunya hanya muncul bila ada transaksi lewat gateway tersebut
            'metode' => collect(['cash', 'transfer', ...$pembayaran->whereIn('metode', self::PAYMENT_GATEWAY)->pluck('metode')->unique()->sort()])->map(fn ($m) => [
                'metode' => $m,
                'total' => (int) $pembayaran->where('metode', $m)->sum('jml_bayar'),
                'jumlah' => $pembayaran->where('metode', $m)->count(),
            ]),
            'rekening' => $pembayaran->where('metode', 'transfer')->groupBy(fn (Pembayaran $p) => $p->rekening?->label() ?? '-')
                ->map(fn ($list, $label) => ['rekening' => $label, 'jumlah' => $list->count(), 'total' => (int) $list->sum('jml_bayar')])
                ->sortByDesc('total')->values(),
            'pembayaran' => $pembayaran->map(fn (Pembayaran $p) => [
                'no_invoice' => $p->no_invoice,
                'id_pelanggan' => $p->pelanggan?->id_pelanggan ?? '-',
                'nama' => $p->nama_pelanggan,
                'cabang' => $p->lokasi?->nama_lokasi ?? '-',
                'periode' => $p->periode,
                'jml_bayar' => $p->jml_bayar,
                'metode' => $p->metode,
                'rekening' => $p->rekening?->label(),
                'paid_at' => $p->paid_at,
                'petugas' => $p->petugas?->name ?? '-',
            ]),
        ]);
    }

    /**
     * Saldo cabang milik user ber-role Mitra (selalu cabangnya sendiri, bukan dari filter); null untuk role lain.
     * Memakai baris rekap cabang yang sama dengan Dashboard Cabang agar angkanya selalu sama.
     *
     * @return array<string, mixed>|null baris rekap cabang + nama_mitra
     */
    private function saldoMitra(Request $request, array $f): ?array
    {
        $user = $request->user();
        $lokasi = $user->id_lokasi && $user->hasRole('Mitra') ? Lokasi::find($user->id_lokasi) : null;
        if (! $lokasi) {
            return null;
        }

        return [...RekapCabang::hitung($lokasi->newCollection([$lokasi]), $this->periode($f))->first(), 'nama_mitra' => $lokasi->nama_mitra];
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
