<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Cabang / lokasi (database). Tarif pajak & bagi hasil per bulan menyusul di Pengaturan Pajak. */
class CabangController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);

        return Inertia::render('KelolaData/Cabang', [
            'filters' => $f,
            'rows' => Lokasi::with('mitra:id,name')
                ->withCount(['area as jumlah_area', 'pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif')])
                ->when($f['cabang'], fn ($q) => $q->whereKey($f['cabang']))
                ->orderBy('kode_lokasi')->get()
                ->map(fn (Lokasi $l) => [...$l->toArray(), 'nama_mitra' => $l->mitra?->name]),
            'mitraOptions' => User::where('role', 'Mitra')->orderBy('name')->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'periodeSekarang' => today()->format('Y-m-01'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $lokasi = Lokasi::create($this->validated($request));

        return response()->json(['message' => "Cabang {$lokasi->nama_lokasi} ditambahkan"]);
    }

    public function update(Request $request, Lokasi $lokasi): JsonResponse
    {
        $lokasi->update($this->validated($request, $lokasi));

        return response()->json(['message' => "Cabang {$lokasi->nama_lokasi} diperbarui"]);
    }

    /** Area ikut terhapus; cabang yang masih dipakai pelanggan, router, atau paket tidak boleh dihapus. */
    public function destroy(Lokasi $lokasi): JsonResponse
    {
        $dipakai = array_filter([
            'pelanggan' => $lokasi->pelanggan()->exists(),
            'router' => $lokasi->routers()->exists(),
            'paket' => $lokasi->paket()->exists(),
        ]);
        if ($dipakai) {
            return response()->json(['message' => "Cabang {$lokasi->nama_lokasi} masih dipakai ".implode(', ', array_keys($dipakai)).', pindahkan dulu ke cabang lain'], 422);
        }
        $lokasi->delete();

        return response()->json(['message' => "Cabang {$lokasi->nama_lokasi} dihapus"]);
    }

    private function validated(Request $request, ?Lokasi $lokasi = null): array
    {
        return $request->validate([
            'kode_lokasi' => ['required', 'string', 'max:20', Rule::unique('lokasi')->ignore($lokasi)],
            'nama_lokasi' => ['required', 'string', 'max:100'],
            'alamat_lokasi' => ['required', 'string', 'max:500'],
            'tlp_lokasi' => ['nullable', 'string', 'max:30'],
            'tlp_owner' => ['nullable', 'string', 'max:30'],
            'id_mitra' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'Mitra')],
            'group_wa' => ['nullable', 'string', 'max:255'],
            'terima_cash' => ['boolean'],
            'status' => ['required', 'in:aktif,tidak_aktif'],
        ], $this->pesanValidasi(), [
            'kode_lokasi' => 'Kode lokasi', 'nama_lokasi' => 'Nama lokasi', 'alamat_lokasi' => 'Alamat', 'tlp_lokasi' => 'Tlp lokasi',
            'tlp_owner' => 'Tlp owner', 'id_mitra' => 'Mitra', 'group_wa' => 'ID grup WA', 'status' => 'Status',
        ]);
    }
}
