<?php

namespace App\Http\Controllers;

use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Services\Mikrotik\IsolirService;
use App\Services\Mikrotik\RouterOsException;
use App\Support\MockData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Data pelanggan (diisi user) + On/Off internet lewat isolir PPPoE di Mikrotik.
 * status null = semua pelanggan, 'on' = internet aktif, 'off' = terisolir.
 */
class PelangganController extends Controller
{
    public function index(Request $request, ?string $status = null)
    {
        $f = $this->filters($request);
        $lokasi = MockData::lokasi()->keyBy('id');
        $base = Pelanggan::query()->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']));

        $rows = (clone $base)->with(['router:id,nama_router,profile_isolir', 'paket:id,nama_paket,harga,profile_mikrotik'])
            ->when($status, fn ($q) => $q->where('status', 'aktif')->where('status_langganan', $status))
            ->orderBy('nama')->get()
            ->map(fn (Pelanggan $p) => [
                ...$p->toArray(),
                'cabang' => $lokasi[$p->id_lokasi]['nama_lokasi'] ?? '-',
                'paket' => $p->paket?->nama_paket ?? '-',
                'harga' => $p->paket?->harga,
                'profile_paket' => $p->paket?->profile_mikrotik,
            ]);

        return Inertia::render('Pelanggan/Index', [
            'filters' => $f,
            'status' => $status,
            'rows' => $rows,
            'jumlah' => [
                'semua' => (clone $base)->count(),
                'on' => (clone $base)->where('status', 'aktif')->where('status_langganan', 'on')->count(),
                'off' => (clone $base)->where('status', 'aktif')->where('status_langganan', 'off')->count(),
            ],
            'routerOptions' => Router::orderBy('nama_router')->get()->map(fn ($r) => ['value' => $r->id, 'label' => $r->nama_router]),
            'paketOptions' => Paket::where('jns_koneksi', 'pppoe')->orderBy('nama_paket')->get()
                ->map(fn ($p) => ['value' => $p->id, 'label' => "{$p->nama_paket} ({$p->profile_mikrotik})"]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $pelanggan = Pelanggan::create($this->validated($request));
        // Kode pelanggan otomatis bila tidak diisi
        if (! $pelanggan->id_pelanggan) {
            $pelanggan->update(['id_pelanggan' => sprintf('P%05d', $pelanggan->id)]);
        }

        return response()->json(['message' => "Pelanggan {$pelanggan->nama} ditambahkan"]);
    }

    public function update(Request $request, Pelanggan $pelanggan): JsonResponse
    {
        $pelanggan->update($this->validated($request, $pelanggan));

        return response()->json(['message' => "Data {$pelanggan->nama} diperbarui"]);
    }

    public function destroy(Pelanggan $pelanggan): JsonResponse
    {
        $pelanggan->delete();

        return response()->json(['message' => "Pelanggan {$pelanggan->nama} dihapus"]);
    }

    /** Off: isolir PPPoE pelanggan. */
    public function isolir(Pelanggan $pelanggan, IsolirService $isolir): JsonResponse
    {
        return $this->jalankan(fn () => $isolir->isolir($pelanggan));
    }

    /** On: kembalikan ke profile asal (atau profile pilihan bila asal tidak diketahui). */
    public function aktifkan(Request $request, Pelanggan $pelanggan, IsolirService $isolir): JsonResponse
    {
        $profile = $request->validate(['profile' => ['nullable', 'string', 'max:100']])['profile'] ?? null;

        return $this->jalankan(fn () => $isolir->aktifkan($pelanggan, $profile));
    }

    /** Kesalahan dari router dikembalikan sebagai 422 berisi pesan yang bisa ditampilkan. */
    private function jalankan(callable $fn): JsonResponse
    {
        try {
            return response()->json(['message' => $fn()]);
        } catch (RouterOsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function validated(Request $request, ?Pelanggan $pelanggan = null): array
    {
        return $request->validate([
            'id_pelanggan' => ['nullable', 'string', 'max:50', Rule::unique('pelanggan')->ignore($pelanggan)],
            'nama' => ['required', 'string', 'max:100'],
            'tlp' => ['nullable', 'string', 'max:30'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'id_lokasi' => ['required', 'integer'],
            'id_paket' => ['nullable', 'integer', 'exists:paket,id'],
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'username_pppoe' => ['required', 'string', 'max:100',
                Rule::unique('pelanggan')->where('router_id', $request->input('router_id'))->ignore($pelanggan)],
            'tgl_registrasi' => ['nullable', 'date'],
            'tgl_jatuh_tempo' => ['nullable', 'integer', 'between:1,31'],
            'status' => ['required', 'in:aktif,berhenti'],
        ], $this->pesanValidasi(), [
            'id_pelanggan' => 'ID pelanggan', 'nama' => 'Nama', 'id_lokasi' => 'Cabang', 'id_paket' => 'Paket', 'router_id' => 'Router',
            'username_pppoe' => 'Username PPPoE', 'tgl_registrasi' => 'Tanggal registrasi', 'tgl_jatuh_tempo' => 'Tanggal jatuh tempo', 'status' => 'Status',
        ]);
    }
}
