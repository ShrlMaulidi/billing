<?php

namespace App\Http\Controllers;

use App\Support\MockData;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);
        $today = MockData::today();
        $periode = $today->format('Y-m-01');
        $byCabang = fn ($c) => $f['cabang'] ? $c->where('id_lokasi', $f['cabang']) : $c;

        $pelanggan = $byCabang(MockData::pelanggan());
        $tagihan = $byCabang(MockData::tagihan()->where('periode', $periode));
        $bayar = $byCabang(MockData::pembayaran());

        return Inertia::render('Dashboard/Index', [
            'filters' => $f,
            'kpi' => [
                'total' => $pelanggan->where('status', '!=', 'berhenti')->count(),
                'lunas' => $tagihan->where('status', 'lunas')->count(),
                'belum_lunas' => $tagihan->where('status', '!=', 'lunas')->count(),
                'on' => $pelanggan->where('status', 'aktif')->where('status_langganan', 'on')->count(),
                'off' => $pelanggan->where('status', 'aktif')->where('status_langganan', 'off')->count(),
                'berhenti' => $pelanggan->where('status', 'berhenti')->count(),
                'pemasukan_bulan' => $bayar->where('periode', $periode)->sum('jml_bayar'),
                'pemasukan_hari' => $bayar->filter(fn ($b) => str_starts_with($b['paid_at'], $today->toDateString()))->sum('jml_bayar'),
            ],
            'pelangganBaru' => $pelanggan->filter(fn ($p) => str_starts_with($p['tgl_registrasi'], $today->format('Y-m')))->sortByDesc('tgl_registrasi')->take(8)->values(),
            'wagw' => MockData::wagw(),
            'grafik' => collect(MockData::periods())->map(fn ($p) => ['periode' => $p, 'total' => $bayar->where('periode', $p)->sum('jml_bayar')]),
        ]);
    }

    public function pelanggan(Request $request)
    {
        $f = $this->filters($request);
        $kategori = $request->input('kategori', 'total');
        $periode = MockData::today()->format('Y-m-01');
        $tagihan = MockData::tagihan()->where('periode', $periode)->keyBy('id_pelanggan');
        $rows = MockData::pelanggan()
            ->when($f['cabang'], fn ($c) => $c->where('id_lokasi', $f['cabang']))
            ->map(fn ($p) => $p + ['status_tagihan' => $tagihan[$p['id']]['status'] ?? null])
            ->filter(fn ($p) => match ($kategori) {
                'lunas' => $p['status_tagihan'] === 'lunas',
                'belum_lunas' => $p['status_tagihan'] && $p['status_tagihan'] !== 'lunas',
                'on' => $p['status'] === 'aktif' && $p['status_langganan'] === 'on',
                'off' => $p['status'] === 'aktif' && $p['status_langganan'] === 'off',
                'berhenti' => $p['status'] === 'berhenti',
                'baru' => str_starts_with($p['tgl_registrasi'], substr($periode, 0, 7)),
                default => $p['status'] !== 'berhenti',
            })->values();

        return Inertia::render('Dashboard/Pelanggan', ['filters' => $f + ['kategori' => $kategori], 'rows' => $rows]);
    }

    public function cabang(Request $request)
    {
        $f = $this->filters($request);

        return Inertia::render('DashboardCabang/Index', [
            'filters' => $f,
            'rows' => MockData::rekapCabang($this->periode($f)),
        ]);
    }

    public function cabangDetail(Request $request, int $id)
    {
        $f = $this->filters($request);
        $periode = $this->periode($f);
        $lokasi = MockData::lokasi()->firstWhere('id', $id) ?? abort(404);

        return Inertia::render('DashboardCabang/Detail', [
            'filters' => $f,
            'lokasi' => $lokasi,
            'rekap' => MockData::rekapCabang($periode, $id)[0],
            'pelanggan' => MockData::pelanggan()->where('id_lokasi', $id)->values(),
            'pembayaran' => MockData::pembayaran()->where('id_lokasi', $id)->where('periode', $periode)->values(),
            'pengeluaran' => MockData::pengeluaran()->where('id_lokasi', $id)->filter(fn ($p) => str_starts_with($p['waktu'], substr($periode, 0, 7)))->values(),
        ]);
    }

    public function income(Request $request)
    {
        $f = $this->filters($request);
        $bayar = MockData::pembayaran()->where('periode', $this->periode($f))
            ->when($f['cabang'], fn ($c) => $c->where('id_lokasi', $f['cabang']))->values();

        $rekening = $bayar->where('metode', 'transfer')->groupBy('rekening')
            ->map(fn ($g, $k) => ['rekening' => $k, 'total' => $g->sum('jml_bayar'), 'jumlah' => $g->count()])->values();

        return Inertia::render('IncomeSummary/Index', [
            'filters' => $f,
            'total' => ['total' => $bayar->sum('jml_bayar'), 'jumlah' => $bayar->count()],
            'metode' => collect(['cash', 'transfer', 'tripay', 'xendit', 'pakar_digital'])->map(fn ($m) => [
                'metode' => $m, 'total' => $bayar->where('metode', $m)->sum('jml_bayar'), 'jumlah' => $bayar->where('metode', $m)->count(),
            ]),
            'rekening' => $rekening,
            'pembayaran' => $bayar,
        ]);
    }
}
