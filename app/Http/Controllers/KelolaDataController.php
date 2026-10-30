<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KelolaDataController extends Controller
{
    /** Menu yang belum punya tabel: tampil kosong sampai terhubung ke database. */
    protected function kosong(Request $request, string $page, array $extra = [])
    {
        return Inertia::render("KelolaData/$page", ['filters' => $this->filters($request), 'rows' => []] + $extra);
    }

    public function kartuMember(Request $request)
    {
        $f = $this->filters($request);

        return Inertia::render('KelolaData/KartuMember', [
            'filters' => $f,
            'rows' => Pelanggan::with('paket:id,nama_paket,harga')->where('status', 'aktif')
                ->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']))
                ->orderBy('nama')->get()
                ->map->toBaris(),
        ]);
    }

    public function cabang(Request $request)
    {
        return $this->kosong($request, 'Cabang', ['periodeSekarang' => today()->format('Y-m-01')]);
    }

    public function area(Request $request)
    {
        return $this->kosong($request, 'Area');
    }

    public function pajak(Request $request)
    {
        $f = $this->filters($request);
        // Tahun yang bisa diatur tarifnya: tahun lalu s/d tahun depan
        $tahun = today()->year;
        $tahunOptions = [$tahun - 1, $tahun, $tahun + 1];
        if (! in_array($f['tahun'], $tahunOptions)) {
            $f['tahun'] = $tahun;
        }

        return Inertia::render('KelolaData/Pajak', [
            'filters' => $f,
            'rows' => [],
            'tahunOptions' => $tahunOptions,
            'periodeSekarang' => today()->format('Y-m-01'),
        ]);
    }

    public function rekening(Request $request)
    {
        return $this->kosong($request, 'Rekening');
    }

    public function notifikasi(Request $request)
    {
        return $this->kosong($request, 'Notifikasi', ['wagw' => ['devices' => [], 'sent' => 0, 'outbox' => 0, 'failed' => 0]]);
    }

    public function pph(Request $request)
    {
        return $this->kosong($request, 'Pph');
    }
}
