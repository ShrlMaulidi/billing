<?php

namespace App\Http\Controllers;

use App\Models\Tunnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TunnelController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('KelolaData/Tunnel', [
            'filters' => $this->filters($request),
            'rows' => Tunnel::withCount('routers')->with('routers:id,tunnel_id,nama_router')->orderBy('nama_tunnel')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tunnel = Tunnel::create($this->validated($request));

        return response()->json(['message' => "Tunnel {$tunnel->nama_tunnel} ditambahkan"]);
    }

    public function update(Request $request, Tunnel $tunnel): JsonResponse
    {
        $tunnel->update($this->validated($request, $tunnel));

        return response()->json(['message' => "Tunnel {$tunnel->nama_tunnel} diperbarui"]);
    }

    public function destroy(Tunnel $tunnel): JsonResponse
    {
        if ($tunnel->routers()->exists()) {
            return response()->json(['message' => "Tunnel {$tunnel->nama_tunnel} masih dipakai router, ubah router tersebut dulu"], 422);
        }
        $tunnel->delete();

        return response()->json(['message' => "Tunnel {$tunnel->nama_tunnel} dihapus"]);
    }

    /** Script untuk ditempel di terminal router turunan agar dial tunnel ke server. */
    public function script(Tunnel $tunnel): JsonResponse
    {
        $interface = strtolower($tunnel->protokol).'-client';
        $q = fn ($v) => '"'.addcslashes($v, '"\\').'"';

        return response()->json(['script' => implode("\n", [
            "# Tunnel {$tunnel->nama_tunnel} ({$tunnel->protokol}) — jalankan di router turunan",
            "/interface $interface add name={$q($tunnel->nama_tunnel)} connect-to={$q($tunnel->server)} user={$q($tunnel->username)} password={$q($tunnel->password)} disabled=no comment=\"Tunnel billing MSS\"",
            '',
            "# Pastikan API aktif agar billing bisa terhubung lewat IP tunnel ({$tunnel->ip_tunnel})",
            '/ip service set api disabled=no port=8728',
        ])]);
    }

    private function validated(Request $request, ?Tunnel $tunnel = null): array
    {
        $data = $request->validate([
            'nama_tunnel' => ['required', 'string', 'max:100'],
            'protokol' => ['required', 'in:L2TP,SSTP,PPTP'],
            'server' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100'],
            'password' => [$tunnel ? 'nullable' : 'required', 'string', 'max:255'],
            'ip_tunnel' => ['required', 'ip'],
            'status' => ['required', 'in:aktif,tidak_aktif'],
        ], $this->pesanValidasi(), [
            'nama_tunnel' => 'Nama tunnel', 'protokol' => 'Protokol', 'server' => 'Server tunnel', 'username' => 'Username',
            'password' => 'Password', 'ip_tunnel' => 'IP router di tunnel', 'status' => 'Status',
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
