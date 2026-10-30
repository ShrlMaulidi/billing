<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\PajakCabang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Cabang / lokasi (database). Tarif pajak & bagi hasil diisi saat tambah cabang, lalu diatur per bulan di Pengaturan Pajak. */
class CabangController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);
        $periode = today()->format('Y-m-01');
        $rows = Lokasi::withCount(['area as jumlah_area', 'pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif')])
            ->when($f['cabang'], fn ($q) => $q->whereKey($f['cabang']))
            ->orderBy('kode_lokasi')->get();
        // Tarif bulan berjalan dari Pengaturan Pajak (null bila cabang belum punya tarif)
        $tarif = PajakCabang::berlaku($rows->modelKeys(), $periode);

        return Inertia::render('KelolaData/Cabang', [
            'filters' => $f,
            'rows' => $rows->map(fn (Lokasi $l) => [...$l->toArray(), ...($tarif->get($l->id)?->only(PajakCabang::TARIF) ?? [])]),
            'periodeSekarang' => $periode,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // User cabang tidak boleh membuat cabang lain
        abort_if($request->user()->id_lokasi !== null, 403, 'Anda tidak memiliki akses ke fitur ini');
        $data = $this->validated($request, tarif: true);
        $tahun = today()->year;

        // Tarif awal berlaku Januari–Desember tahun berjalan; per bulan bisa diubah di Pengaturan Pajak
        $lokasi = DB::transaction(function () use ($data, $tahun) {
            $lokasi = Lokasi::create(Arr::except($data, PajakCabang::TARIF));
            $lokasi->pajak()->createMany(array_map(
                fn (int $bulan) => ['periode' => sprintf('%04d-%02d-01', $tahun, $bulan), ...Arr::only($data, PajakCabang::TARIF)],
                range(1, 12),
            ));

            return $lokasi;
        });

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
            'pembayaran' => $lokasi->pembayaran()->exists(),
            'rekening' => $lokasi->rekening()->exists(),
        ]);
        if ($dipakai) {
            return response()->json(['message' => "Cabang {$lokasi->nama_lokasi} masih dipakai ".implode(', ', array_keys($dipakai)).', pindahkan dulu ke cabang lain'], 422);
        }
        $lokasi->delete();

        return response()->json(['message' => "Cabang {$lokasi->nama_lokasi} dihapus"]);
    }

    private function validated(Request $request, ?Lokasi $lokasi = null, bool $tarif = false): array
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
            ...($tarif ? PajakCabang::aturan($request->all()) : []),
        ], $this->pesanValidasi(), [
            'kode_lokasi' => 'Kode lokasi', 'nama_lokasi' => 'Nama lokasi', 'alamat_lokasi' => 'Alamat', 'tlp_lokasi' => 'Tlp lokasi',
            'tlp_owner' => 'Tlp owner', 'nama_mitra' => 'Mitra / pemilik', 'group_wa' => 'ID grup WA', 'status' => 'Status',
            ...PajakCabang::ATRIBUT,
        ]);
    }
}
