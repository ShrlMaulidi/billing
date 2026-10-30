<?php

namespace App\Http\Controllers;

use App\Models\Tunnel;
use App\Services\Tunnel\TunnelRadius;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Tunnel L2TP untuk router turunan ber-IP private: akun disimpan ke FreeRADIUS (lihat TunnelRadius),
 * router turunan dial ke server L2TP di VPS dan mendapat IP tetap di jaringan tunnel.
 */
class TunnelController extends Controller
{
    public function index(Request $request, TunnelRadius $radius)
    {
        $tunnels = Tunnel::withCount('routers')->with('routers:id,tunnel_id,nama_router')->orderBy('nama_tunnel')->get();
        $sesi = $radius->sesiAktif($tunnels->pluck('username')->all());

        return Inertia::render('KelolaData/Tunnel', [
            'filters' => $this->filters($request),
            'rows' => $tunnels->map(fn (Tunnel $t) => [...$t->toArray(), 'sesi' => $sesi[$t->username] ?? null]),
            'saran' => ['server' => config('services.tunnel.server'), 'ip_tunnel' => $this->ipKosong($tunnels->pluck('ip_tunnel')->all())],
            'jaringan' => ['subnet' => config('services.tunnel.subnet'), 'gateway' => config('services.tunnel.gateway')],
        ]);
    }

    public function store(Request $request, TunnelRadius $radius): JsonResponse
    {
        $tunnel = DB::transaction(function () use ($request, $radius) {
            $tunnel = Tunnel::create($this->validated($request, $radius));
            $radius->simpan($tunnel);

            return $tunnel;
        });

        return response()->json(['message' => "Tunnel {$tunnel->nama_tunnel} ditambahkan"]);
    }

    public function update(Request $request, Tunnel $tunnel, TunnelRadius $radius): JsonResponse
    {
        $data = $this->validated($request, $radius, $tunnel);
        DB::transaction(function () use ($tunnel, $data, $radius) {
            $usernameLama = $tunnel->username;
            $tunnel->update($data);
            $radius->simpan($tunnel, $usernameLama);
        });

        return response()->json(['message' => "Tunnel {$tunnel->nama_tunnel} diperbarui"]);
    }

    public function destroy(Tunnel $tunnel, TunnelRadius $radius): JsonResponse
    {
        if ($tunnel->routers()->exists()) {
            return response()->json(['message' => "Tunnel {$tunnel->nama_tunnel} masih dipakai router, ubah router tersebut dulu"], 422);
        }
        DB::transaction(function () use ($tunnel, $radius) {
            $radius->hapus($tunnel);
            $tunnel->delete();
        });

        return response()->json(['message' => "Tunnel {$tunnel->nama_tunnel} dihapus"]);
    }

    /** Script untuk ditempel di terminal router turunan agar dial tunnel ke server. */
    public function script(Tunnel $tunnel): JsonResponse
    {
        $q = fn ($v) => '"'.addcslashes($v, '"\\$').'"';

        return response()->json(['script' => implode("\n", [
            "# Tunnel {$tunnel->nama_tunnel} (L2TP) — jalankan di New Terminal router turunan",
            "/interface l2tp-client add name={$q($tunnel->nama_tunnel)} connect-to={$q($tunnel->server)} user={$q($tunnel->username)} password={$q($tunnel->password)} allow=chap add-default-route=no disabled=no comment=\"Tunnel billing MSS\"",
            '',
            "# Pastikan API aktif agar billing bisa terhubung lewat IP tunnel ({$tunnel->ip_tunnel})",
            '/ip service set api disabled=no port=8728',
        ])]);
    }

    private function validated(Request $request, TunnelRadius $radius, ?Tunnel $tunnel = null): array
    {
        $gateway = config('services.tunnel.gateway');
        $subnet = config('services.tunnel.subnet');

        $data = $request->validate([
            'nama_tunnel' => ['required', 'string', 'max:100'],
            'server' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('tunnels')->ignore($tunnel),
                function (string $attribute, mixed $value, Closure $fail) use ($radius, $tunnel) {
                    if ($radius->dipakaiAkunLain($value, $tunnel)) {
                        $fail('Username sudah dipakai akun RADIUS lain');
                    }
                }],
            'password' => [$tunnel ? 'nullable' : 'required', 'string', 'max:128'],
            'ip_tunnel' => ['required', 'ipv4', Rule::unique('tunnels')->ignore($tunnel),
                function (string $attribute, mixed $value, Closure $fail) use ($gateway, $subnet) {
                    if ($value === $gateway) {
                        $fail("$value adalah IP server tunnel, pakai IP lain");
                    } elseif (! $this->dalamSubnet($value, $subnet)) {
                        $fail("IP router di tunnel harus di dalam jaringan $subnet");
                    }
                }],
            'status' => ['required', 'in:aktif,tidak_aktif'],
        ], [...$this->pesanValidasi(), 'ipv4' => ':attribute harus berupa alamat IPv4 yang valid'], [
            'nama_tunnel' => 'Nama tunnel', 'server' => 'Server tunnel', 'username' => 'Username',
            'password' => 'Password', 'ip_tunnel' => 'IP router di tunnel', 'status' => 'Status',
        ]);
        // Server tunnel di VPS hanya melayani L2TP
        $data['protokol'] = 'L2TP';
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }

    private function dalamSubnet(string $ip, string $subnet): bool
    {
        [$jaringan, $prefix] = explode('/', $subnet);
        $mask = -1 << (32 - (int) $prefix);

        return (ip2long($ip) & $mask) === (ip2long($jaringan) & $mask);
    }

    /**
     * IP pertama di jaringan tunnel yang belum dipakai (selain gateway), untuk isian awal form.
     *
     * @param  array<int, string>  $dipakai
     */
    private function ipKosong(array $dipakai): ?string
    {
        [$jaringan, $prefix] = explode('/', config('services.tunnel.subnet'));
        $awal = ip2long($jaringan);
        $dipakai = [...$dipakai, config('services.tunnel.gateway')];
        for ($i = 1; $i < 2 ** (32 - (int) $prefix) - 1; $i++) {
            $ip = long2ip($awal + $i);
            if (! in_array($ip, $dipakai, true)) {
                return $ip;
            }
        }

        return null;
    }
}
