<?php

namespace App\Http\Controllers;

use App\Models\Paket;
use App\Models\Router;
use App\Services\Mikrotik\IsolirService;
use App\Services\Mikrotik\RouterOsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Paket langganan (database). Profile Mikrotik paket dipakai sebagai tujuan saat pelanggan di-On-kan,
 * dan bisa dibuat/diperbarui otomatis di router (rate-limit, local address, IP pool).
 */
class PaketController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);
        $rows = Paket::with('router:id,nama_router')
            ->withCount(['pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif')])
            ->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']))
            ->orderBy('nama_paket')->get()
            ->map(fn (Paket $p) => [...$p->toArray(), 'cabang' => '-']);

        return Inertia::render('KelolaData/Paket', [
            'filters' => $f,
            'rows' => $rows,
            'routerOptions' => Router::orderBy('nama_router')->get()->map(fn ($r) => ['value' => $r->id, 'label' => $r->nama_router]),
        ]);
    }

    public function store(Request $request, IsolirService $isolir): JsonResponse
    {
        $paket = Paket::create($this->validated($request));

        return $this->simpanDanSinkron($paket, "Paket {$paket->nama_paket} ditambahkan", $isolir);
    }

    public function update(Request $request, Paket $paket, IsolirService $isolir): JsonResponse
    {
        $paket->update($this->validated($request, $paket));

        return $this->simpanDanSinkron($paket, "Paket {$paket->nama_paket} diperbarui", $isolir);
    }

    public function destroy(Paket $paket): JsonResponse
    {
        if ($paket->pelanggan()->exists()) {
            return response()->json(['message' => "Paket {$paket->nama_paket} masih dipakai pelanggan, pindahkan pelanggan ke paket lain dulu"], 422);
        }
        $paket->delete();

        return response()->json(['message' => "Paket {$paket->nama_paket} dihapus"]);
    }

    /** Tombol "Sinkron ke MikroTik". */
    public function sinkron(Paket $paket, IsolirService $isolir): JsonResponse
    {
        [$berhasil, $pesan] = $this->sinkronSemua($paket, $isolir);

        return response()->json(['message' => $pesan], $berhasil ? 200 : 422);
    }

    /** Data tetap tersimpan walau sinkron gagal; kegagalan dikembalikan sebagai peringatan. */
    private function simpanDanSinkron(Paket $paket, string $pesan, IsolirService $isolir): JsonResponse
    {
        if (! $paket->buat_profile) {
            return response()->json(['message' => $pesan]);
        }
        [$berhasil, $hasil] = $this->sinkronSemua($paket, $isolir);

        return response()->json(['message' => "$pesan. $hasil", 'type' => $berhasil ? 'success' : 'warning']);
    }

    /**
     * Sinkron ke router paket, atau ke semua router bila paket tidak dibatasi router.
     *
     * @return array{0: bool, 1: string}
     */
    private function sinkronSemua(Paket $paket, IsolirService $isolir): array
    {
        $routers = $paket->router_id ? Router::whereKey($paket->router_id)->get() : Router::orderBy('nama_router')->get();
        if ($routers->isEmpty()) {
            return [false, 'Belum ada router untuk menyinkronkan profile'];
        }

        $berhasil = [];
        $gagal = [];
        foreach ($routers as $router) {
            try {
                $berhasil[] = $isolir->sinkronPaket($paket, $router);
            } catch (RouterOsException $e) {
                $gagal[] = "{$router->nama_router}: {$e->getMessage()}";
            }
        }
        $paket->update(['synced_at' => $gagal ? $paket->synced_at : now(), 'sync_error' => $gagal ? implode('; ', $gagal) : null]);

        return [! $gagal, implode('; ', [...$berhasil, ...$gagal])];
    }

    private function validated(Request $request, ?Paket $paket = null): array
    {
        $data = $request->validate([
            'nama_paket' => ['required', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'harga' => ['required', 'integer', 'min:0'],
            'jenis' => ['required', 'in:bulanan,non_bulanan'],
            'jns_koneksi' => ['required', 'in:pppoe,hotspot'],
            'id_lokasi' => ['required_if:filter_lokasi,true', 'nullable', 'integer'],
            'filter_lokasi' => ['boolean'],
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
            'profile_mikrotik' => ['required', 'string', 'max:100'],
            // Format rate-limit Mikrotik: rx/tx, boleh dengan burst, mis. 10M/10M atau 10M/10M 20M/20M 8M/8M 16/16
            'rate_limit' => ['nullable', 'string', 'max:100', 'regex:/^[0-9kKmMgG\/ ]+$/'],
            'local_address' => ['nullable', 'ip'],
            'buat_profile' => ['boolean'],
            'buat_pool' => ['boolean'],
            'nama_pool' => ['required_if:buat_pool,true', 'nullable', 'string', 'max:100'],
            'range_pool' => ['required_if:buat_pool,true', 'nullable', 'string', 'max:255', 'regex:/^[0-9.\-, ]+$/'],
            'tampil_registrasi' => ['boolean'],
        ], $this->pesanValidasi(), [
            'nama_paket' => 'Nama paket', 'keterangan' => 'Keterangan', 'harga' => 'Harga', 'jenis' => 'Jenis', 'jns_koneksi' => 'Jenis koneksi',
            'id_lokasi' => 'Cabang', 'router_id' => 'Router', 'profile_mikrotik' => 'Profile MikroTik', 'rate_limit' => 'Rate limit',
            'local_address' => 'Local address', 'nama_pool' => 'Nama pool', 'range_pool' => 'Range IP pool',
        ]);

        if (empty($data['buat_pool'])) {
            $data['nama_pool'] = null;
            $data['range_pool'] = null;
        }

        return $data;
    }
}
