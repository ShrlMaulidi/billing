<?php

namespace App\Services\Mikrotik;

use App\Models\IsolirLog;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use Throwable;

/**
 * Isolir PPPoE: profile PPP Secret pelanggan diubah ke profile isolir router (profile asal disimpan),
 * lalu sesi aktif diputus agar langsung berlaku. Aktifkan = kembalikan ke profile asal (atau profile paket).
 * Profile paket disinkronkan ke router lewat sinkronPaket().
 */
class IsolirService
{
    public function __construct(private MikrotikConnector $connector) {}

    /** @return array{identity: string, version: string, board: string, uptime: string} */
    public function test(Router $router): array
    {
        return $this->onRouter($router, function (MikrotikGateway $gw) use ($router) {
            $info = $gw->info();
            $router->update(['identity' => $info['identity'], 'versi_routeros' => $info['version']]);

            return $info;
        });
    }

    /** @return array<int, string> */
    public function profiles(Router $router): array
    {
        return $this->onRouter($router, fn (MikrotikGateway $gw) => $gw->profiles());
    }

    /** @return string pesan hasil */
    public function isolir(Pelanggan $pelanggan): string
    {
        $router = $this->routerOf($pelanggan);
        $tujuan = $router->profile_isolir;

        return $this->catat($pelanggan, 'isolir', $tujuan, function (MikrotikGateway $gw) use ($pelanggan, $tujuan) {
            $secret = $this->secretDiRouter($gw, $pelanggan);
            if ($secret['profile'] === $tujuan) {
                $pelanggan->update(['status_langganan' => 'off', 'isolir_at' => $pelanggan->isolir_at ?? now()]);

                return [$secret['profile'], "{$pelanggan->nama} sudah dalam profile isolir"];
            }
            $this->pastikanProfileAda($gw, $tujuan, 'Profile isolir');

            $gw->setSecretProfile($secret['id'], $tujuan);
            $diputus = $gw->removeActive($pelanggan->username_pppoe);
            $pelanggan->update(['profile_asal' => $secret['profile'], 'status_langganan' => 'off', 'isolir_at' => now()]);

            return [$secret['profile'], "{$pelanggan->nama} diisolir ({$secret['profile']} → $tujuan)".($diputus ? ', sesi aktif diputus' : '')];
        });
    }

    /**
     * Tujuan profile: pilihan user → profile asal sebelum isolir → profile paket pelanggan.
     *
     * @param  string|null  $profile  dipakai bila profile asal tidak diketahui (mis. diisolir manual di router)
     */
    public function aktifkan(Pelanggan $pelanggan, ?string $profile = null): string
    {
        $this->routerOf($pelanggan);
        $tujuan = $profile ?: $pelanggan->profile_asal ?: $pelanggan->paket?->profile_mikrotik;

        return $this->catat($pelanggan, 'aktifkan', $tujuan, function (MikrotikGateway $gw) use ($pelanggan, $tujuan) {
            $secret = $this->secretDiRouter($gw, $pelanggan);
            $isolir = $pelanggan->router->profile_isolir;
            if (! $tujuan) {
                // Tidak terisolir di router → cukup samakan status di database
                if ($secret['profile'] !== $isolir) {
                    $pelanggan->update(['status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null]);

                    return [$secret['profile'], "{$pelanggan->nama} sudah aktif dengan profile {$secret['profile']}"];
                }
                throw new RouterOsException("Profile asal {$pelanggan->nama} tidak diketahui, pilih profile yang akan dipakai");
            }
            if ($secret['profile'] === $tujuan) {
                $pelanggan->update(['status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null]);

                return [$secret['profile'], "{$pelanggan->nama} sudah aktif dengan profile $tujuan"];
            }
            $this->pastikanProfileAda($gw, $tujuan, 'Profile');

            $gw->setSecretProfile($secret['id'], $tujuan);
            $diputus = $gw->removeActive($pelanggan->username_pppoe);
            $pelanggan->update(['status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null]);

            return [$secret['profile'], "{$pelanggan->nama} diaktifkan kembali ({$secret['profile']} → $tujuan)".($diputus ? ', sesi diputus agar login ulang' : '')];
        });
    }

    /**
     * Buat/perbarui profile paket (beserta IP pool bila diisi) di router, agar profile tujuan saat On selalu tersedia.
     *
     * @return string pesan hasil
     */
    public function sinkronPaket(Paket $paket, Router $router): string
    {
        // Menimpa profile isolir akan membuat pelanggan terisolir ikut mendapat kecepatan paket
        if ($paket->profile_mikrotik === $router->profile_isolir) {
            throw new RouterOsException("Profile '{$paket->profile_mikrotik}' adalah profile isolir router {$router->nama_router}, pakai nama lain");
        }

        return $this->onRouter($router, function (MikrotikGateway $gw) use ($paket, $router) {
            $pool = $paket->buat_pool ? $paket->nama_pool : null;
            if ($pool) {
                $gw->savePool($pool, $paket->range_pool);
            }
            $attributes = $paket->jns_koneksi === 'hotspot'
                ? ['rate-limit' => $paket->rate_limit, 'address-pool' => $pool]
                : ['rate-limit' => $paket->rate_limit, 'local-address' => $paket->local_address, 'remote-address' => $pool];
            $baru = $gw->saveProfile($paket->jns_koneksi, $paket->profile_mikrotik, array_filter($attributes, fn ($v) => filled($v)));

            return "{$router->nama_router}: profile {$paket->profile_mikrotik} ".($baru ? 'dibuat' : 'diperbarui');
        });
    }

    private function routerOf(Pelanggan $pelanggan): Router
    {
        return $pelanggan->router ?? throw new RouterOsException("{$pelanggan->nama} belum dihubungkan ke router, edit data pelanggan dulu");
    }

    /** Jalankan aksi isolir/aktifkan sambil mencatat log berhasil maupun gagal. */
    private function catat(Pelanggan $pelanggan, string $aksi, ?string $tujuan, callable $fn): string
    {
        try {
            [$dari, $pesan] = $this->onRouter($pelanggan->router, $fn);
            $this->log($pelanggan, $aksi, $dari, $tujuan, true, $pesan);

            return $pesan;
        } catch (Throwable $e) {
            $this->log($pelanggan, $aksi, null, $tujuan, false, $e->getMessage());
            throw $e;
        }
    }

    /** Buka koneksi, jalankan $fn, dan perbarui status koneksi router. */
    private function onRouter(Router $router, callable $fn): mixed
    {
        try {
            $result = $fn($this->connector->connect($router));
            $router->update(['status_koneksi' => 'online', 'last_error' => null, 'last_checked_at' => now()]);

            return $result;
        } catch (RouterOsException $e) {
            // Perintah yang ditolak berarti router tetap terjangkau
            $router->update([
                'status_koneksi' => $e->jenis === 'perintah' ? 'online' : 'offline',
                'last_error' => $e->jenis === 'perintah' ? $router->last_error : $e->getMessage(),
                'last_checked_at' => now(),
            ]);
            throw $e;
        }
    }

    private function secretDiRouter(MikrotikGateway $gw, Pelanggan $pelanggan): array
    {
        return $gw->findSecret($pelanggan->username_pppoe)
            ?? throw new RouterOsException("PPP Secret '{$pelanggan->username_pppoe}' tidak ditemukan di router {$pelanggan->router->nama_router}");
    }

    private function pastikanProfileAda(MikrotikGateway $gw, string $profile, string $label): void
    {
        if (! in_array($profile, $gw->profiles(), true)) {
            throw new RouterOsException("$label '$profile' belum ada di router, buat dulu di PPP > Profiles");
        }
    }

    private function log(Pelanggan $pelanggan, string $aksi, ?string $dari, ?string $ke, bool $berhasil, string $pesan): void
    {
        IsolirLog::create([
            'router_id' => $pelanggan->router_id, 'pelanggan_id' => $pelanggan->id, 'username' => $pelanggan->username_pppoe,
            'aksi' => $aksi, 'profile_dari' => $dari, 'profile_ke' => $ke, 'berhasil' => $berhasil, 'pesan' => $pesan,
        ]);
    }
}
