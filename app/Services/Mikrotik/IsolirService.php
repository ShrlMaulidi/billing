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
        $this->pastikanPppoe($pelanggan);
        $router = $this->routerOf($pelanggan);
        $tujuan = $router->profile_isolir;

        return $this->catat($pelanggan, 'isolir', $tujuan, function (MikrotikGateway $gw) use ($pelanggan, $tujuan) {
            $secret = $this->secretDiRouter($gw, $pelanggan);
            if ($secret['profile'] === $tujuan) {
                $pelanggan->update(['status_langganan' => 'off', 'isolir_at' => $pelanggan->isolir_at ?? now()]);

                return [$secret['profile'], "{$pelanggan->nama} sudah dalam profile isolir"];
            }
            $this->pastikanProfileAda($gw, $tujuan, 'Profile isolir');

            $gw->setSecret($secret['id'], ['profile' => $tujuan]);
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
        $this->pastikanPppoe($pelanggan);
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

            $gw->setSecret($secret['id'], ['profile' => $tujuan]);
            $diputus = $gw->removeActive($pelanggan->username_pppoe);
            $pelanggan->update(['status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null]);

            return [$secret['profile'], "{$pelanggan->nama} diaktifkan kembali ({$secret['profile']} → $tujuan)".($diputus ? ', sesi diputus agar login ulang' : '')];
        });
    }

    /**
     * Data pelanggan diedit: PPP Secret ikut diperbarui lalu sesi diputus agar langsung berlaku.
     * Ganti paket → profile paket baru; pelanggan yang sedang terisolir tetap di profile isolir dan
     * profile paket baru dipakai saat diaktifkan kembali. Ganti IP → remote address secret.
     *
     * @return string pesan hasil
     */
    public function perbaruiSecret(Pelanggan $pelanggan, bool $gantiPaket, bool $gantiIp): string
    {
        $this->pastikanPppoe($pelanggan);
        $this->routerOf($pelanggan);
        $profile = null;
        if ($gantiPaket) {
            $profile = $pelanggan->paket?->profile_mikrotik ?? throw new RouterOsException("Paket {$pelanggan->nama} belum punya profile MikroTik");
            if ($pelanggan->status_langganan === 'off') {
                $pelanggan->update(['profile_asal' => $profile]);
                $profile = null;
            }
        }

        return $this->catat($pelanggan, $gantiPaket ? 'ganti_paket' : 'ganti_ip', $profile, function (MikrotikGateway $gw) use ($pelanggan, $profile, $gantiIp) {
            $secret = $this->secretDiRouter($gw, $pelanggan);
            $perubahan = array_filter([
                'profile' => $profile !== $secret['profile'] ? $profile : null,
                'remote-address' => $gantiIp && $pelanggan->ip_address !== $secret['remote_address'] ? $pelanggan->ip_address : null,
            ]);
            if (! $perubahan) {
                $catatan = $pelanggan->status_langganan === 'off' ? 'sedang terisolir, profile paket baru dipakai saat diaktifkan kembali' : 'akun di router sudah sesuai';

                return [$secret['profile'], $catatan];
            }
            if (isset($perubahan['profile'])) {
                $this->pastikanProfileAda($gw, $profile, 'Profile');
            }

            $gw->setSecret($secret['id'], $perubahan);
            $diputus = $gw->removeActive($pelanggan->username_pppoe);
            $hasil = array_filter([
                isset($perubahan['profile']) ? "profile router {$secret['profile']} → $profile" : null,
                isset($perubahan['remote-address']) ? "remote address {$perubahan['remote-address']}" : null,
            ]);

            return [$secret['profile'], implode(', ', $hasil).($diputus ? ', sesi diputus agar login ulang' : '')];
        });
    }

    /**
     * Akun koneksi yang sudah ada di router, untuk pilihan "Sinkronisasi" di form pelanggan.
     *
     * @return list<array{name: string, profile: ?string, address: ?string, comment: ?string}>
     */
    public function akun(Router $router, string $jenis): array
    {
        return $this->onRouter($router, fn (MikrotikGateway $gw) => $gw->accounts($jenis));
    }

    /**
     * IP PPPoE yang sudah terpakai di router (Remote Address PPP Secret & IP sesi aktif) beserta IP Pool-nya,
     * agar IP pelanggan baru tidak bentrok walau akun lama belum tercatat di billing.
     *
     * @return array{pools: list<array{name: string, ranges: string}>, terpakai: array<string, string>} terpakai: IP => username
     */
    public function ipPppoe(Router $router): array
    {
        return $this->onRouter($router, function (MikrotikGateway $gw) {
            $terpakai = [];
            foreach ([...$gw->accounts('pppoe'), ...$gw->activeSessions()] as $row) {
                if (filter_var($row['address'] ?? null, FILTER_VALIDATE_IP)) {
                    $terpakai[$row['address']] ??= $row['name'];
                }
            }

            return ['pools' => $gw->pools(), 'terpakai' => $terpakai];
        });
    }

    /**
     * Pilihan "Buat baru": buat PPP Secret / Hotspot User / IP Binding pelanggan di router-nya,
     * memakai profile paket pelanggan.
     *
     * @return string pesan hasil
     */
    public function buatAkun(Pelanggan $pelanggan, ?string $password): string
    {
        $router = $this->routerOf($pelanggan);
        $jenis = $pelanggan->jns_koneksi;
        $label = ['pppoe' => 'PPP Secret', 'hotspot' => 'Hotspot User', 'ip_binding' => 'IP Binding'][$jenis]
            ?? throw new RouterOsException('Koneksi '.Pelanggan::KONEKSI[$jenis].' tidak memakai akun di router');
        $nama = $pelanggan->akunKoneksi();

        return $this->onRouter($router, function (MikrotikGateway $gw) use ($pelanggan, $router, $jenis, $label, $nama, $password) {
            if (in_array($nama, array_column($gw->accounts($jenis), 'name'), true)) {
                throw new RouterOsException("$label '$nama' sudah ada di router {$router->nama_router}, pilih Sinkronisasi");
            }
            $comment = trim("{$pelanggan->id_pelanggan} {$pelanggan->nama}");
            $attributes = $jenis === 'ip_binding'
                ? ['mac-address' => $nama, 'address' => $pelanggan->ip_address, 'type' => 'bypassed', 'comment' => $comment]
                : ['name' => $nama, 'password' => $password, 'profile' => $pelanggan->paket?->profile_mikrotik, 'comment' => $comment]
                    + ($jenis === 'pppoe' ? ['service' => 'pppoe', 'remote-address' => $pelanggan->ip_address] : []);
            $gw->addAccount($jenis, array_filter($attributes, fn ($v) => filled($v)));

            return "$label '$nama' dibuat di router {$router->nama_router}";
        });
    }

    /**
     * Profile paket yang sudah ada di router untuk pilihan "Sinkronisasi" di form paket, tanpa profile isolir.
     *
     * @return list<array{name: string, rate_limit: ?string}>
     */
    public function profilePaket(Router $router, string $jenis): array
    {
        $rows = $this->onRouter($router, fn (MikrotikGateway $gw) => $gw->profileList($jenis));

        return array_values(array_filter($rows, fn ($row) => $row['name'] !== $router->profile_isolir));
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

    /** Isolir bekerja lewat profile PPP Secret, jadi On/Off baru tersedia untuk PPPoE. */
    private function pastikanPppoe(Pelanggan $pelanggan): void
    {
        if ($pelanggan->jns_koneksi !== 'pppoe') {
            throw new RouterOsException('On/Off internet untuk koneksi '.Pelanggan::KONEKSI[$pelanggan->jns_koneksi].' belum tersedia, baru PPPoE');
        }
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
