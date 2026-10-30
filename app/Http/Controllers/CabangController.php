<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
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
            'rows' => Lokasi::withCount(['area as jumlah_area', 'pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif')])
                ->when($f['cabang'], fn ($q) => $q->whereKey($f['cabang']))
                ->orderBy('kode_lokasi')->get(),
            'periodeSekarang' => today()->format('Y-m-01'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // User cabang tidak boleh membuat cabang lain
        abort_if($request->user()->id_lokasi !== null, 403, 'Anda tidak memiliki akses ke fitur ini');
        $lokasi = Lokasi::create($this->validated($request));

        return response()->json(['message' => "Cabang {$lokasi->nama_lokasi} ditambahkan"]);
    }

    public function update(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);
        $lokasi->update($this->validated($request, $lokasi));

        return response()->json(['message' => "Cabang {$lokasi->nama_lokasi} diperbarui"]);
    }

    /**
     * Area ikut terhapus; cabang yang masih dipakai pelanggan, router, paket, atau akun user tidak boleh dihapus
     * (user tanpa cabang = akses semua cabang, jadi menghapus cabangnya sama dengan menaikkan hak aksesnya).
     */
    public function destroy(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);
        $dipakai = array_filter([
            'pelanggan' => $lokasi->pelanggan()->exists(),
            'router' => $lokasi->routers()->exists(),
            'paket' => $lokasi->paket()->exists(),
            'akun user' => $lokasi->users()->exists(),
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
            'nama_mitra' => ['nullable', 'string', 'max:100'],
            'group_wa' => ['nullable', 'string', 'max:255'],
            'terima_cash' => ['boolean'],
            'status' => ['required', 'in:aktif,tidak_aktif'],
        ], $this->pesanValidasi(), [
            'kode_lokasi' => 'Kode lokasi', 'nama_lokasi' => 'Nama lokasi', 'alamat_lokasi' => 'Alamat', 'tlp_lokasi' => 'Tlp lokasi',
            'tlp_owner' => 'Tlp owner', 'nama_mitra' => 'Mitra / pemilik', 'group_wa' => 'ID grup WA', 'status' => 'Status',
        ]);
    }
}
