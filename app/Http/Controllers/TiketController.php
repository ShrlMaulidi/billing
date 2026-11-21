<?php

namespace App\Http\Controllers;

use App\Models\Tiket;
use App\Models\TiketPesan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu staf Tiket: tiket dari dashboard pelanggan diterima cabang pelanggan masing-masing (Mitra / user cabang hanya melihat
 * tiket cabangnya, tiket cabang lain dijawab 404); user pusat melihat semua. Balas & ubah status (Baru → Diproses → Selesai).
 */
class TiketController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $f['status'] = array_key_exists((string) $request->input('status'), Tiket::STATUS) ? $request->input('status') : null;

        return Inertia::render('Tiket/Index', [
            'filters' => $f,
            'rows' => Tiket::with(['pelanggan:id,id_pelanggan,nama', 'lokasi:id,nama_lokasi'])
                ->when($f['cabang'], fn ($q, $cabang) => $q->where('id_lokasi', $cabang))
                ->when($f['status'], fn ($q, $status) => $q->where('status', $status))
                // Yang belum selesai dulu, lalu yang terakhir diperbarui
                ->orderByRaw("CASE WHEN status = 'selesai' THEN 1 ELSE 0 END")->latest('updated_at')
                ->limit(500)->get()->map(fn (Tiket $t) => $t->toBaris()),
            'statusOptions' => collect(Tiket::STATUS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function show(Request $request, Tiket $tiket): Response
    {
        $this->pastikanCabang($request, $tiket->id_lokasi);
        $tiket->load(['pelanggan:id,id_pelanggan,nama,tlp,alamat', 'lokasi:id,nama_lokasi', 'pesan.user:id,name']);

        return Inertia::render('Tiket/Detail', [
            'tiket' => [...$tiket->toBaris(), 'tlp' => $tiket->pelanggan?->tlp, 'alamat' => $tiket->pelanggan?->alamat],
            'pesan' => $tiket->pesan->map(fn (TiketPesan $p) => $p->toBaris()),
            'statusOptions' => collect(Tiket::STATUS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    /** Balasan petugas; tiket baru otomatis jadi Diproses. */
    public function balas(Request $request, Tiket $tiket): JsonResponse
    {
        $this->pastikanCabang($request, $tiket->id_lokasi);
        $data = $request->validate(['pesan' => ['required', 'string', 'max:2000']], $this->pesanValidasi(), ['pesan' => 'Balasan']);

        $tiket->pesan()->create(['user_id' => $request->user()->id, 'dari_pelanggan' => false, 'pesan' => $data['pesan']]);
        $tiket->status === 'baru' ? $tiket->update(['status' => 'diproses']) : $tiket->touch();

        return response()->json(['message' => 'Balasan terkirim']);
    }

    public function status(Request $request, Tiket $tiket): JsonResponse
    {
        $this->pastikanCabang($request, $tiket->id_lokasi);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Tiket::STATUS))]], $this->pesanValidasi(), ['status' => 'Status']);
        $tiket->update(['status' => $data['status'], 'selesai_at' => $data['status'] === 'selesai' ? now() : null]);

        return response()->json(['message' => "Tiket {$tiket->no_tiket} ".strtolower(Tiket::STATUS[$data['status']])]);
    }
}
