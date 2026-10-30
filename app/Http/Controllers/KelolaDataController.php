<?php

namespace App\Http\Controllers;

use App\Support\MockData;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class KelolaDataController extends Controller
{
    protected function list(Request $request, string $page, Collection $rows, array $extra = [])
    {
        $f = $this->filters($request);
        if ($f['cabang']) {
            $rows = $rows->filter(fn ($r) => ($r['id_lokasi'] ?? null) === $f['cabang'] || ($r['id'] ?? null) === $f['cabang'] && $page === 'Cabang');
        }

        return Inertia::render("KelolaData/$page", ['filters' => $f, 'rows' => $rows->values()] + $extra);
    }

    public function kartuMember(Request $request)
    {
        return $this->list($request, 'KartuMember', MockData::pelanggan()->where('status', 'aktif'));
    }

    public function cabang(Request $request)
    {
        $jumlah = MockData::pelanggan()->where('status', 'aktif')->countBy('id_lokasi');
        $jumlahArea = MockData::area()->countBy('id_lokasi');
        $periode = MockData::today()->format('Y-m-01');
        // Pajak & bagi hasil hanya ditampilkan (bulan berjalan); diatur di Pengaturan Pajak
        $tarif = ['ppn_persen', 'pph_persen', 'bhp_persen', 'uso_persen', 'admin_fee_tipe', 'admin_fee', 'metode_bagi_hasil', 'nilai_bagi_hasil'];

        return $this->list($request, 'Cabang', MockData::lokasi()->map(fn ($l) => array_merge($l, Arr::only(MockData::pajakLokasi($l['id'], $periode), $tarif), [
            'jumlah_pelanggan' => $jumlah[$l['id']] ?? 0,
            'jumlah_area' => $jumlahArea[$l['id']] ?? 0,
        ])), ['periodeSekarang' => $periode]);
    }

    public function area(Request $request)
    {
        // Sementara pelanggan dibagi rata ke area cabangnya (data dummy belum punya kolom area)
        $areaPerCabang = MockData::area()->groupBy('id_lokasi');
        $jumlah = MockData::pelanggan()->where('status', 'aktif')
            ->map(fn ($p) => $areaPerCabang[$p['id_lokasi']][$p['id'] % $areaPerCabang[$p['id_lokasi']]->count()]['id'])
            ->countBy();

        return $this->list($request, 'Area', MockData::area()->map(fn ($a) => $a + ['jumlah_pelanggan' => $jumlah[$a['id']] ?? 0]));
    }

    public function pajak(Request $request)
    {
        $f = $this->filters($request);
        $f['cabang'] ??= MockData::lokasi()->first()['id'];
        $tahunOptions = MockData::tahunPajak();
        if (! in_array($f['tahun'], $tahunOptions)) {
            $f['tahun'] = MockData::today()->year;
        }

        return Inertia::render('KelolaData/Pajak', [
            'filters' => $f,
            'rows' => MockData::pajak()->where('id_lokasi', $f['cabang'])->where('tahun', $f['tahun'])->sortBy('bulan')->values(),
            'tahunOptions' => $tahunOptions,
            'periodeSekarang' => MockData::today()->format('Y-m-01'),
        ]);
    }

    public function rekening(Request $request)
    {
        return $this->list($request, 'Rekening', MockData::rekening());
    }

    public function notifikasi(Request $request)
    {
        return $this->list($request, 'Notifikasi', MockData::notifikasi(), ['wagw' => MockData::wagw()]);
    }

    public function pph(Request $request)
    {
        $tahun = (int) $request->input('tahun', MockData::today()->year);

        return $this->list($request, 'Pph', MockData::pphUpload()->filter(fn ($p) => str_starts_with($p['periode'], (string) $tahun)));
    }
}
