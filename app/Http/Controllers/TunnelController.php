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

    /**
     * Template script untuk New Terminal router mitra: dial tunnel, aktifkan API di port pilihan,
     * izinkan server billing di firewall, dan (opsional) buat user API. Aman di-paste ulang.
     */
    public function script(Request $request, Tunnel $tunnel): JsonResponse
    {
        $router = $tunnel->routers()->orderBy('id')->first();
        $opsi = $request->validate([
            'port_api' => ['nullable', 'integer', 'between:1,65535'],
            'api_user' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'api_password' => ['nullable', 'string', 'max:128'],
        ], $this->pesanValidasi(), ['port_api' => 'Port API', 'api_user' => 'Username API', 'api_password' => 'Password API']);
        $port = (int) ($opsi['port_api'] ?? $router?->port_api ?? 8728);
        $gateway = config('services.tunnel.gateway');

        $q = fn ($v) => '"'.addcslashes($v, '"\\$').'"';
        $izinApi = "/ip firewall filter add chain=input src-address=$gateway protocol=tcp dst-port=$port action=accept comment=\"API billing MSS\"";
        $baris = [
            "# ===== Tunnel billing MSS: {$tunnel->nama_tunnel} =====",
            '# Paste di New Terminal router mitra. Aman di-paste ulang (konfigurasi lama dari billing dihapus dulu).',
            '',
            '# 1. Tunnel L2TP ke server billing',
            '/interface l2tp-client remove [find comment="Tunnel billing MSS"]',
            "/interface l2tp-client add name={$q($tunnel->nama_tunnel)} connect-to={$q($tunnel->server)} user={$q($tunnel->username)} password={$q($tunnel->password)} allow=chap add-default-route=no disabled=no comment=\"Tunnel billing MSS\"",
            '',
            "# 2. API MikroTik di port $port (samakan dengan Port API di menu Router billing)",
            "/ip service set api disabled=no port=$port",
            '',
            "# 3. Izinkan server billing ($gateway) mengakses API lewat tunnel, ditaruh paling atas",
            '/ip firewall filter remove [find comment="API billing MSS"]',
            "{ :local r [/ip firewall filter find where dynamic=no]; :if ([:len \$r] > 0) do={ $izinApi place-before=[:pick \$r 0] } else={ $izinApi } }",
        ];
        if (! empty($opsi['api_user']) && ! empty($opsi['api_password'])) {
            array_push($baris, '',
                '# 4. User API khusus billing (isi Username/Password API yang sama di menu Router)',
                ':if ([:len [/user group find name="billing-api"]] = 0) do={ /user group add name="billing-api" policy=read,write,api,test }',
                "/user remove [find name={$q($opsi['api_user'])}]",
                "/user add name={$q($opsi['api_user'])} password={$q($opsi['api_password'])} group=\"billing-api\" comment=\"API billing MSS\"",
            );
        }
        array_push($baris, '',
            '# Cek: interface harus R (running) dan mendapat IP '.$tunnel->ip_tunnel,
            '/interface l2tp-client print where comment="Tunnel billing MSS"',
        );

        return response()->json([
            'script' => implode("\n", $baris),
            'opsi' => ['port_api' => $port, 'api_user' => $opsi['api_user'] ?? $router?->username ?? 'billing-api'],
        ]);
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
