<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Models\Tunnel;
use App\Services\Mikrotik\IsolirService;
use App\Services\Mikrotik\RouterOsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RouterController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);
        $rows = Router::with(['tunnel:id,nama_tunnel,ip_tunnel', 'lokasi:id,nama_lokasi'])->withCount('pelanggan')->orderBy('nama_router')->get()
            ->when($f['cabang'], fn ($c) => $c->where('id_lokasi', $f['cabang']))
            ->map(fn (Router $r) => [
                ...$r->toArray(),
                'cabang' => $r->lokasi?->nama_lokasi ?? '-',
                'alamat_api' => $r->alamatApi(),
            ])->values();

        return Inertia::render('KelolaData/Router', [
            'filters' => $f,
            'rows' => $rows,
            'tunnelOptions' => Tunnel::orderBy('nama_tunnel')->get()->map(fn ($t) => ['value' => $t->id, 'label' => "{$t->nama_tunnel} ({$t->ip_tunnel})"]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $router = Router::create($this->validated($request));

        return response()->json(['message' => "Router {$router->nama_router} ditambahkan"]);
    }

    public function update(Request $request, Router $router): JsonResponse
    {
        $this->pastikanCabang($request, $router->id_lokasi);
        $router->update($this->validated($request, $router));

        return response()->json(['message' => "Router {$router->nama_router} diperbarui"]);
    }

    public function destroy(Request $request, Router $router): JsonResponse
    {
        $this->pastikanCabang($request, $router->id_lokasi);
        $router->delete();

        return response()->json(['message' => "Router {$router->nama_router} dihapus"]);
    }

    public function test(Request $request, Router $router, IsolirService $isolir): JsonResponse
    {
        $this->pastikanCabang($request, $router->id_lokasi);
        try {
            $info = $isolir->test($router);
        } catch (RouterOsException $e) {
            return response()->json(['message' => "{$router->nama_router}: {$e->getMessage()}"], 422);
        }

        return response()->json([
            'message' => "Terhubung ke {$info['identity']} (RouterOS {$info['version']}, {$info['board']}, uptime {$info['uptime']})",
            'data' => $info,
        ]);
    }

    public function profiles(Request $request, Router $router, IsolirService $isolir): JsonResponse
    {
        // Router tanpa cabang dipakai bersama semua cabang
        abort_unless($router->id_lokasi === null || $request->user()->bolehAksesCabang($router->id_lokasi), 404);
        try {
            return response()->json(['data' => $isolir->profiles($router)]);
        } catch (RouterOsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function validated(Request $request, ?Router $router = null): array
    {
        $data = $request->validate([
            'kode_router' => ['nullable', 'string', 'max:50'],
            'nama_router' => ['required', 'string', 'max:100'],
            'id_lokasi' => ['nullable', 'integer', 'exists:lokasi,id', ...$this->aturanCabangSendiri($request)],
            'mode_koneksi' => ['required', 'in:langsung,tunnel'],
            'host' => ['required_if:mode_koneksi,langsung', 'nullable', 'string', 'max:255'],
            'tunnel_id' => ['required_if:mode_koneksi,tunnel', 'nullable', 'integer', 'exists:tunnels,id'],
            'port_api' => ['required', 'integer', 'between:1,65535'],
            'ssl' => ['boolean'],
            'username' => ['required', 'string', 'max:100'],
            // Saat edit, password boleh dikosongkan agar tidak berubah
            'password' => [$router ? 'nullable' : 'required', 'string', 'max:255'],
            'profile_isolir' => ['required', 'string', 'max:100'],
        ], $this->pesanValidasi(), [
            'nama_router' => 'Nama router', 'mode_koneksi' => 'Mode koneksi', 'host' => 'Host / IP publik', 'tunnel_id' => 'Tunnel',
            'port_api' => 'Port API', 'username' => 'Username API', 'password' => 'Password API', 'profile_isolir' => 'Profile isolir',
        ]);

        if ($data['mode_koneksi'] === 'langsung') {
            $data['tunnel_id'] = null;
        } else {
            $data['host'] = null;
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        // Detail koneksi berubah → status lama tidak berlaku lagi
        $data['status_koneksi'] = 'belum_dicek';

        return $data;
    }
}
