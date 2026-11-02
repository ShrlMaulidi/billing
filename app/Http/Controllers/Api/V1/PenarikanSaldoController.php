<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PenarikanSaldoResource;
use App\Models\Lokasi;
use App\Models\PenarikanSaldo;
use App\Support\RekapCabang;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Daftar pengajuan penarikan saldo + saldo tersedia per periode untuk form pengajuan.
 * Pengajuan, setujui, tolak, dan bukti transfer memakai TransaksiController web.
 */
class PenarikanSaldoController extends Controller
{
    /** Filter bulan pengajuan (bulan & tahun) atau semua=1 untuk semua periode, status, dan cabang (user pusat). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::in(PenarikanSaldo::STATUS)], 'semua' => ['nullable', 'boolean']], $this->pesanValidasi());
        $f = $this->filters($request);
        $periode = Carbon::parse($this->periode($f));
        $periodeOpsi = RekapCabang::periodeTransaksi();
        $lokasi = Lokasi::when($request->user()->id_lokasi, fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get();

        $penarikan = PenarikanSaldo::with(['lokasi:id,nama_lokasi,nama_mitra', 'pemohon:id,name', 'pemroses:id,name'])
            ->when($f['cabang'], fn ($q, $id) => $q->where('id_lokasi', $id))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->unless($request->boolean('semua'), fn ($q) => $q->whereBetween('created_at', [$periode->copy()->startOfDay(), $periode->copy()->endOfMonth()]))
            ->latest()->paginate(25)->withQueryString();

        return PenarikanSaldoResource::collection($penarikan)->additional([
            // Saldo yang masih bisa diajukan: [id_lokasi => [periode => saldo]]
            'saldo_tersedia' => (object) RekapCabang::saldoTersedia($lokasi, $periodeOpsi),
            'periode_opsi' => $periodeOpsi,
        ]);
    }
}
