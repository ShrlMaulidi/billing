<?php

namespace App\Http\Controllers;

use App\Models\Area;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Area di dalam cabang (database). Pelanggan di area yang dihapus tetap ada, hanya kehilangan area-nya. */
class AreaController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);

        return Inertia::render('KelolaData/Area', [
            'filters' => $f,
            'rows' => Area::with('lokasi:id,nama_lokasi')
                ->withCount(['pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif')])
                ->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']))
                ->orderBy('id_lokasi')->orderBy('nama_area')->get()
                ->map(fn (Area $a) => [...$a->toArray(), 'cabang' => $a->lokasi?->nama_lokasi]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $area = Area::create($this->validated($request));

        return response()->json(['message' => "Area {$area->nama_area} ditambahkan"]);
    }

    public function update(Request $request, Area $area): JsonResponse
    {
        $this->pastikanCabang($request, $area->id_lokasi);
        $area->update($this->validated($request));

        return response()->json(['message' => "Area {$area->nama_area} diperbarui"]);
    }

    public function destroy(Request $request, Area $area): JsonResponse
    {
        $this->pastikanCabang($request, $area->id_lokasi);
        $area->delete();

        return response()->json(['message' => "Area {$area->nama_area} dihapus"]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'id_lokasi' => ['required', 'integer', 'exists:lokasi,id', ...$this->aturanCabangSendiri($request)],
            'kode_area' => ['nullable', 'string', 'max:30'],
            'nama_area' => ['required', 'string', 'max:100'],
            'cakupan' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:aktif,tidak_aktif'],
        ], $this->pesanValidasi(), [
            'id_lokasi' => 'Cabang', 'kode_area' => 'Kode area', 'nama_area' => 'Nama area', 'cakupan' => 'Cakupan wilayah', 'status' => 'Status',
        ]);
    }
}
