<?php

namespace App\Http\Controllers;

use App\Support\MockData;
use Illuminate\Http\Request;
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

        return $this->list($request, 'Cabang', MockData::lokasi()->map(fn ($l) => $l + ['jumlah_pelanggan' => $jumlah[$l['id']] ?? 0]));
    }

    public function pajak(Request $request)
    {
        return $this->list($request, 'Pajak', MockData::pajak());
    }

    public function paket(Request $request)
    {
        $jumlah = MockData::pelanggan()->where('status', 'aktif')->countBy('id_paket');

        return $this->list($request, 'Paket', MockData::paket()->map(fn ($p) => $p + ['jumlah_pelanggan' => $jumlah[$p['id']] ?? 0]), [
            'routerOptions' => MockData::router()->map(fn ($r) => ['value' => $r['id'], 'label' => $r['nama_router']])->values(),
        ]);
    }

    public function router(Request $request)
    {
        $jumlah = MockData::pelanggan()->where('status', 'aktif')->countBy('id_router');

        return $this->list($request, 'Router', MockData::router()->map(fn ($r) => $r + ['jumlah_pelanggan' => $jumlah[$r['id']] ?? 0]));
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

    public function tunnel(Request $request)
    {
        return $this->list($request, 'Tunnel', MockData::tunnel(), [
            'routerOptions' => MockData::router()->map(fn ($r) => ['value' => $r['id'], 'label' => $r['nama_router'].' — '.$r['cabang']])->values(),
        ]);
    }
}
