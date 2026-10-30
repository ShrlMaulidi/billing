<?php

namespace App\Http\Controllers;

use App\Models\Rekening;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Rekening tujuan transfer (database); dipakai sebagai pilihan metode saat input pembayaran pelanggan. */
class RekeningController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);

        return Inertia::render('KelolaData/Rekening', [
            'filters' => $f,
            // Rekening tanpa cabang berlaku di semua cabang, jadi ikut tampil saat filter cabang dipakai
            'rows' => Rekening::with('lokasi:id,nama_lokasi')
                ->when($f['cabang'], fn ($q) => $q->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $f['cabang'])))
                ->orderBy('nama_rekening')->get()
                ->map(fn (Rekening $r) => [...$r->toArray(), 'cabang' => $r->lokasi?->nama_lokasi ?? 'Semua cabang']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rekening = Rekening::create($this->validated($request));

        return response()->json(['message' => "Rekening {$rekening->nama_rekening} ditambahkan"]);
    }

    public function update(Request $request, Rekening $rekening): JsonResponse
    {
        $this->pastikanCabangDikelola($request, $rekening);
        $rekening->update($this->validated($request));

        return response()->json(['message' => "Rekening {$rekening->nama_rekening} diperbarui"]);
    }

    public function destroy(Request $request, Rekening $rekening): JsonResponse
    {
        $this->pastikanCabangDikelola($request, $rekening);
        if ($rekening->pembayaran()->exists()) {
            return response()->json(['message' => "Rekening {$rekening->nama_rekening} sudah dipakai di pembayaran sehingga tidak bisa dihapus"], 422);
        }
        $rekening->delete();

        return response()->json(['message' => "Rekening {$rekening->nama_rekening} dihapus"]);
    }

    /** Rekening semua cabang hanya boleh diubah user semua cabang; user cabang hanya rekening cabangnya. */
    private function pastikanCabangDikelola(Request $request, Rekening $rekening): void
    {
        abort_if($rekening->id_lokasi === null && $request->user()->id_lokasi !== null, 404);
        $this->pastikanCabang($request, $rekening->id_lokasi);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'nama_rekening' => ['required', 'string', 'max:100'],
            'atas_nama' => ['required', 'string', 'max:100'],
            'no_rekening' => ['required', 'string', 'max:50', 'regex:/^[0-9 .\-]+$/'],
            'id_lokasi' => ['nullable', 'integer', 'exists:lokasi,id', ...$this->aturanCabangSendiri($request)],
            'tampil_invoice' => ['boolean'],
        ], $this->pesanValidasi(), [
            'nama_rekening' => 'Nama bank / e-wallet', 'atas_nama' => 'Atas nama', 'no_rekening' => 'No rekening', 'id_lokasi' => 'Cabang',
            'tampil_invoice' => 'Tampil di invoice',
        ]);
    }
}
