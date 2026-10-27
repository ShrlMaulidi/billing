<?php

namespace App\Http\Controllers;

use App\Support\MockData;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransaksiController extends Controller
{
    protected function filterRows($rows, array $f, string $dateKey, bool $semuaPeriode = false)
    {
        $ym = sprintf('%04d-%02d', $f['tahun'], $f['bulan']);

        return $rows->when($f['cabang'], fn ($c) => $c->where('id_lokasi', $f['cabang']))
            ->when(! $semuaPeriode, fn ($c) => $c->filter(fn ($r) => str_starts_with($r[$dateKey], $ym)))
            ->values();
    }

    public function penarikan(Request $request)
    {
        $f = $this->filters($request) + ['status' => $request->input('status')];
        $rows = $this->filterRows(MockData::penarikanSaldo(), $f, 'created_at', $request->boolean('semua'))
            ->when($f['status'], fn ($c) => $c->where('status', $f['status']))->values();
        $periode = MockData::periods()[10];

        return Inertia::render('Transaksi/Penarikan', [
            'filters' => $f + ['semua' => $request->boolean('semua')],
            'rows' => $rows,
            'saldo' => collect(MockData::rekapCabang($periode))->mapWithKeys(fn ($r) => [$r['id_lokasi'] => $r['sisa_wd']]),
        ]);
    }

    public function setor(Request $request)
    {
        $f = $this->filters($request);
        $semua = $request->boolean('semua');
        $rows = MockData::setorMitra()
            ->when($f['cabang'], fn ($c) => $c->where('id_lokasi', $f['cabang']))
            ->when(! $semua, fn ($c) => $c->where('periode', $this->periode($f)))
            ->sortByDesc('created_at')->values();

        return Inertia::render('Transaksi/Setor', [
            'filters' => $f + ['semua' => $semua],
            'rows' => $rows,
            'kewajiban' => collect(MockData::rekapCabang($this->periode($f)))->mapWithKeys(fn ($r) => [$r['id_lokasi'] => $r['sisa_setor']]),
            'rekeningOptions' => MockData::rekening()->where('id_lokasi', 1)->map(fn ($r) => ['value' => $r['id'], 'label' => $r['nama_rekening'].($r['is_cash'] ? '' : ' - '.$r['no_rekening'])])->values(),
        ]);
    }

    public function pengeluaran(Request $request)
    {
        $f = $this->filters($request);
        $periode = $this->periode($f);
        $rows = $this->filterRows(MockData::pengeluaran(), $f, 'waktu')->sortByDesc('waktu')->values();
        $pemasukan = MockData::pembayaran()->where('periode', $periode)->when($f['cabang'], fn ($c) => $c->where('id_lokasi', $f['cabang']))->sum('jml_bayar');

        return Inertia::render('Transaksi/Pengeluaran', [
            'filters' => $f,
            'rows' => $rows,
            'ringkasan' => ['pemasukan' => $pemasukan, 'pengeluaran' => $rows->sum('nominal'), 'balance' => $pemasukan - $rows->sum('nominal')],
            'master' => MockData::masterPengeluaran()->when($f['cabang'], fn ($c) => $c->where('id_lokasi', $f['cabang']))->values(),
            'rekeningOptions' => MockData::rekening()->map(fn ($r) => ['value' => $r['id'], 'label' => $r['nama_rekening'].($r['is_cash'] ? '' : ' - '.$r['atas_nama'])])->values(),
        ]);
    }
}
