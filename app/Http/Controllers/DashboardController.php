<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Data pelanggan diambil dari database. Tagihan, pembayaran, rekap cabang, dan WAGW belum punya tabel,
 * sehingga nilainya kosong/0 sampai modul tersebut terhubung ke database.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);
        $today = today();
        $pelanggan = Pelanggan::query()->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']));
        $aktif = (clone $pelanggan)->where('status', 'aktif');

        return Inertia::render('Dashboard/Index', [
            'filters' => $f,
            'kpi' => [
                'total' => (clone $aktif)->count(),
                'lunas' => 0,
                'belum_lunas' => 0,
                'on' => (clone $aktif)->where('status_langganan', 'on')->count(),
                'off' => (clone $aktif)->where('status_langganan', 'off')->count(),
                'berhenti' => (clone $pelanggan)->where('status', 'berhenti')->count(),
                'pemasukan_bulan' => 0,
                'pemasukan_hari' => 0,
            ],
            'pelangganBaru' => (clone $pelanggan)->with('paket:id,nama_paket,harga')
                ->whereBetween('tgl_registrasi', $this->bulanIni())
                ->latest('tgl_registrasi')->take(8)->get()
                ->map->toBaris(),
            'wagw' => ['devices' => [], 'sent' => 0, 'outbox' => 0, 'failed' => 0],
            'grafik' => collect(range(11, 0))->map(fn ($i) => [
                'periode' => $today->copy()->startOfMonth()->subMonths($i)->format('Y-m-01'),
                'total' => 0,
            ]),
        ]);
    }

    public function pelanggan(Request $request)
    {
        $f = $this->filters($request);
        $kategori = $request->input('kategori', 'total');
        $query = Pelanggan::with('paket:id,nama_paket,harga')->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']));

        $rows = match ($kategori) {
            // Status lunas/belum lunas menunggu tabel tagihan
            'lunas', 'belum_lunas' => collect(),
            'berhenti' => $query->where('status', 'berhenti')->orderBy('nama')->get(),
            'baru' => $query->whereBetween('tgl_registrasi', $this->bulanIni())->latest('tgl_registrasi')->get(),
            default => $query->where('status', 'aktif')->orderBy('nama')->get(),
        };

        return Inertia::render('Dashboard/Pelanggan', ['filters' => $f + ['kategori' => $kategori], 'rows' => $rows->map->toBaris()]);
    }

    public function cabang(Request $request)
    {
        return Inertia::render('DashboardCabang/Index', [
            'filters' => $this->filters($request),
            'rows' => [],
        ]);
    }

    /** Detail cabang tersedia setelah tabel cabang ada. */
    public function cabangDetail(int $id)
    {
        abort(404);
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
     * Awal dan akhir bulan berjalan sebagai Y-m-d (kolom tgl_registrasi bertipe date).
     *
     * @return array{0: string, 1: string}
     */
    private function bulanIni(): array
    {
        return [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()];
    }
}
