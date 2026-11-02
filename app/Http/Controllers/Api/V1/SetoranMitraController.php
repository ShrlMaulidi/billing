<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SetoranMitraResource;
use App\Models\Lokasi;
use App\Models\SetoranMitra;
use App\Support\RekapCabang;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Daftar setoran mitra per periode kewajiban + kewajiban per cabang. Input & pembatalan (khusus user pusat)
 * dan bukti setor memakai TransaksiController web.
 */
class SetoranMitraController extends Controller
{
    /** Filter periode kewajiban (bulan & tahun) atau semua=1, dan cabang (user pusat). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['semua' => ['nullable', 'boolean']], $this->pesanValidasi());
        $f = $this->filters($request);
        $periodeOpsi = RekapCabang::periodeTransaksi();
        $lokasi = Lokasi::when($request->user()->id_lokasi, fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get();

        $setoran = SetoranMitra::with(['lokasi:id,nama_lokasi,nama_mitra', 'rekening', 'petugas:id,name'])
            ->when($f['cabang'], fn ($q, $id) => $q->where('id_lokasi', $id))
            ->unless($request->boolean('semua'), fn ($q) => $q->where('periode', $this->periode($f)))
            ->latest()->paginate(25)->withQueryString();

        return SetoranMitraResource::collection($setoran)->additional([
            // Kewajiban = saldo minus (uang perusahaan yang masih dipegang mitra), 0 bila saldo tidak minus: [id_lokasi => [periode => nominal]]
            'kewajiban' => (object) collect(RekapCabang::saldo($lokasi, $periodeOpsi))
                ->map(fn (array $perPeriode) => array_map(fn (int $saldo) => max(0, -$saldo), $perPeriode))->all(),
            'periode_opsi' => $periodeOpsi,
        ]);
    }
}
