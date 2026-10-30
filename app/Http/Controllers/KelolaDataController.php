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
            'rows' => Pelanggan::with(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi'])->where('status', 'aktif')
                ->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']))
                ->orderBy('nama')->get()
                ->map->toBaris(),
        ]);
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
