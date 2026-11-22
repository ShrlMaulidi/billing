<?php

namespace App\Services\Mikrotik;

use App\Models\IsolirLog;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use Throwable;

/**
 * On/Off internet pelanggan di router:
 * - PPPoE & Hotspot: profile akun (PPP Secret / Hotspot User) diubah ke profile isolir router (profile asal disimpan),
 *   lalu sesi aktif diputus agar langsung berlaku. Aktifkan = kembalikan ke profile asal (atau profile paket).
 * - Static & IP Binding: IP pelanggan dimasukkan ke address-list ISOLIR. Aktifkan = keluarkan dari address-list.
 * Keduanya mengandalkan aturan firewall dari script Template Isolir. Profile paket disinkronkan lewat sinkronPaket();
 * limit pelanggan Static & IP Binding (tanpa profile) lewat Simple Queue per IP, disinkronkan lewat sinkronQueue().
 */
class IsolirService
{
    /** Address-list pelanggan terisolir, sama dengan yang dipakai script Template Isolir. */
    public const ADDRESS_LIST = 'ISOLIR';

    /** Akun koneksi pelanggan di router untuk tiap jenis koneksi (Static tidak memakai akun). */
    private const AKUN = ['pppoe' => 'PPP Secret', 'hotspot' => 'Hotspot User', 'ip_binding' => 'IP Binding'];

    /** Jenis koneksi yang diisolir lewat profile akun, beserta menu profile-nya di router. */
    private const LEWAT_PROFILE = ['pppoe' => 'PPP > Profiles', 'hotspot' => 'IP > Hotspot > User Profiles'];

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

    /** @return array<int, string> nama profile ($jenis pppoe → PPP Profile, hotspot → Hotspot User Profile) */
    public function profiles(Router $router, string $jenis = 'pppoe'): array
    {
        return $this->onRouter($router, fn (MikrotikGateway $gw) => $this->namaProfile($gw, $jenis));
    }

    /** @return string pesan hasil */
    public function isolir(Pelanggan $pelanggan): string
    {
        $router = $this->routerOf($pelanggan);
        if (! $this->lewatProfile($pelanggan)) {
            return $this->catat($pelanggan, 'isolir', self::ADDRESS_LIST, fn (MikrotikGateway $gw) => $this->isolirAlamat($gw, $pelanggan));
        }
        $tujuan = $router->profile_isolir;

        return $this->catat($pelanggan, 'isolir', $tujuan, function (MikrotikGateway $gw) use ($pelanggan, $tujuan) {
            $akun = $this->akunDiRouter($gw, $pelanggan);
            if ($akun['profile'] === $tujuan) {
                $pelanggan->update(['status_langganan' => 'off', 'isolir_at' => $pelanggan->isolir_at ?? now()]);

                return [$akun['profile'], "{$pelanggan->nama} sudah dalam profile isolir"];
            }
            $this->pastikanProfileAda($gw, $pelanggan->jns_koneksi, $tujuan, 'Profile isolir');

            $gw->setAccount($pelanggan->jns_koneksi, $akun['id'], ['profile' => $tujuan]);
            $diputus = $gw->removeActive($pelanggan->username_pppoe, $pelanggan->jns_koneksi);
            $pelanggan->update(['profile_asal' => $akun['profile'], 'status_langganan' => 'off', 'isolir_at' => now()]);

            return [$akun['profile'], "{$pelanggan->nama} diisolir ({$akun['profile']} → $tujuan)".($diputus ? ', sesi aktif diputus' : '')];
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
        if (! $this->lewatProfile($pelanggan)) {
            return $this->catat($pelanggan, 'aktifkan', null, fn (MikrotikGateway $gw) => $this->aktifkanAlamat($gw, $pelanggan));
        }
        $tujuan = $profile ?: $pelanggan->profile_asal ?: $pelanggan->paket?->profile_mikrotik;

        return $this->catat($pelanggan, 'aktifkan', $tujuan, function (MikrotikGateway $gw) use ($pelanggan, $tujuan) {
            $akun = $this->akunDiRouter($gw, $pelanggan);
            $isolir = $pelanggan->router->profile_isolir;
            if (! $tujuan) {
                // Tidak terisolir di router → cukup samakan status di database
                if ($akun['profile'] !== $isolir) {
                    $pelanggan->update(['status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null]);

                    return [$akun['profile'], "{$pelanggan->nama} sudah aktif dengan profile {$akun['profile']}"];
                }
                throw new RouterOsException("Profile asal {$pelanggan->nama} tidak diketahui, pilih profile yang akan dipakai");
            }
            if ($akun['profile'] === $tujuan) {
                $pelanggan->update(['status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null]);

                return [$akun['profile'], "{$pelanggan->nama} sudah aktif dengan profile $tujuan"];
            }
            $this->pastikanProfileAda($gw, $pelanggan->jns_koneksi, $tujuan, 'Profile');

            $gw->setAccount($pelanggan->jns_koneksi, $akun['id'], ['profile' => $tujuan]);
            $diputus = $gw->removeActive($pelanggan->username_pppoe, $pelanggan->jns_koneksi);
            $pelanggan->update(['status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null]);

            return [$akun['profile'], "{$pelanggan->nama} diaktifkan kembali ({$akun['profile']} → $tujuan)".($diputus ? ', sesi diputus agar login ulang' : '')];
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
            $secret = $this->akunDiRouter($gw, $pelanggan);
            $perubahan = array_filter([
                'profile' => $profile !== $secret['profile'] ? $profile : null,
                'remote-address' => $gantiIp && $pelanggan->ip_address !== $secret['address'] ? $pelanggan->ip_address : null,
            ]);
            if (! $perubahan) {
                $catatan = $pelanggan->status_langganan === 'off' ? 'sedang terisolir, profile paket baru dipakai saat diaktifkan kembali' : 'akun di router sudah sesuai';

                return [$secret['profile'], $catatan];
            }
            if (isset($perubahan['profile'])) {
                $this->pastikanProfileAda($gw, 'pppoe', $profile, 'Profile');
            }

            $gw->setAccount('pppoe', $secret['id'], $perubahan);
            $diputus = $gw->removeActive($pelanggan->username_pppoe);
            $hasil = array_filter([
                isset($perubahan['profile']) ? "profile router {$secret['profile']} → $profile" : null,
                isset($perubahan['remote-address']) ? "remote address {$perubahan['remote-address']}" : null,
            ]);

            return [$secret['profile'], implode(', ', $hasil).($diputus ? ', sesi diputus agar login ulang' : '')];
        });
    }

    /**
     * Simple Queue pelanggan Static & IP Binding: target IP pelanggan, limit dari rate limit paket.
     *
     * @return string pesan hasil
     */
    public function sinkronQueue(Pelanggan $pelanggan): string
    {
        $router = $this->routerOf($pelanggan);

        return $this->onRouter($router, fn (MikrotikGateway $gw) => $this->terapkanQueue($gw, $pelanggan, $gw->queues()))
            ." di router {$router->nama_router}";
    }

    /**
     * Queue semua pelanggan aktif paket Static/IP Binding, satu koneksi per router (dipakai saat rate limit paket berubah).
     *
     * @return array{0: bool, 1: string} semua berhasil & pesan hasil
     */
    public function sinkronQueuePaket(Paket $paket): array
    {
        $perRouter = $paket->pelanggan()->where('status', 'aktif')->whereNotNull('router_id')
            ->with(['router', 'paket'])->orderBy('nama')->get()->groupBy('router_id');
        if ($perRouter->isEmpty()) {
            return [true, 'belum ada pelanggan aktif di paket ini'];
        }

        $hasil = [];
        $semuaBerhasil = true;
        foreach ($perRouter as $pelanggan) {
            $router = $pelanggan->first()->router;
            try {
                $gagal = $this->onRouter($router, function (MikrotikGateway $gw) use ($pelanggan) {
                    $queues = $gw->queues();
                    $gagal = [];
                    foreach ($pelanggan as $p) {
                        try {
                            $this->terapkanQueue($gw, $p, $queues);
                        } catch (RouterOsException $e) {
                            $gagal[] = "{$p->nama} ({$e->getMessage()})";
                        }
                    }

                    return $gagal;
                });
                $berhasil = count($pelanggan) - count($gagal);
                $hasil[] = "{$router->nama_router}: queue $berhasil pelanggan diperbarui".($gagal ? ', gagal: '.implode(', ', $gagal) : '');
                $semuaBerhasil = $semuaBerhasil && ! $gagal;
            } catch (RouterOsException $e) {
                $hasil[] = "{$router->nama_router}: {$e->getMessage()}";
                $semuaBerhasil = false;
            }
        }

        return [$semuaBerhasil, implode('; ', $hasil)];
    }

    /**
     * Jenis koneksi diganti dari Static/IP Binding: queue buatan billing dihapus agar tidak mendahului limit profile.
     *
     * @return string|null pesan hasil, null bila queue tidak ada
     */
    public function hapusQueue(Pelanggan $pelanggan): ?string
    {
        $router = $this->routerOf($pelanggan);

        return $this->onRouter($router, function (MikrotikGateway $gw) use ($pelanggan, $router) {
            $queue = array_find($gw->queues(), fn (array $q) => $q['comment'] === $pelanggan->id_pelanggan);
            if (! $queue) {
                return null;
            }
            $gw->removeQueue($queue['id']);

            return "Queue {$queue['name']} dihapus dari router {$router->nama_router}";
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
        $label = self::AKUN[$jenis]
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

    /** Ganti paket/IP saat edit hanya memperbarui PPP Secret. */
    private function pastikanPppoe(Pelanggan $pelanggan): void
    {
        if ($pelanggan->jns_koneksi !== 'pppoe') {
            throw new RouterOsException('Pembaruan akun router saat edit pelanggan baru tersedia untuk PPPoE');
        }
    }

    private function lewatProfile(Pelanggan $pelanggan): bool
    {
        return isset(self::LEWAT_PROFILE[$pelanggan->jns_koneksi]);
    }

    /**
     * Static & IP Binding: IP pelanggan dimasukkan ke address-list ISOLIR.
     *
     * @return array{0: ?string, 1: string} address-list sebelumnya & pesan hasil
     */
    private function isolirAlamat(MikrotikGateway $gw, Pelanggan $pelanggan): array
    {
        $ip = $this->ipPelanggan($gw, $pelanggan);
        $sudah = $gw->inAddressList(self::ADDRESS_LIST, $ip);
        if (! $sudah) {
            $gw->addAddressList(self::ADDRESS_LIST, $ip, trim("{$pelanggan->id_pelanggan} {$pelanggan->nama}"));
        }
        $pelanggan->update(['status_langganan' => 'off', 'isolir_at' => $pelanggan->isolir_at ?? now()]);

        return $sudah
            ? [self::ADDRESS_LIST, "{$pelanggan->nama} sudah dalam address-list ".self::ADDRESS_LIST]
            : [null, "{$pelanggan->nama} diisolir (IP $ip masuk address-list ".self::ADDRESS_LIST.')'];
    }

    /**
     * Static & IP Binding: IP pelanggan dikeluarkan dari address-list ISOLIR.
     *
     * @return array{0: ?string, 1: string} address-list sebelumnya & pesan hasil
     */
    private function aktifkanAlamat(MikrotikGateway $gw, Pelanggan $pelanggan): array
    {
        $ip = $this->ipPelanggan($gw, $pelanggan);
        $dihapus = $gw->removeAddressList(self::ADDRESS_LIST, $ip);
        $pelanggan->update(['status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null]);

        return $dihapus
            ? [self::ADDRESS_LIST, "{$pelanggan->nama} diaktifkan kembali (IP $ip keluar dari address-list ".self::ADDRESS_LIST.')']
            : [null, "{$pelanggan->nama} sudah aktif, IP $ip tidak ada di address-list ".self::ADDRESS_LIST];
    }

    /**
     * Queue dicari lewat comment = ID pelanggan, lalu lewat target IP (queue buatan manual diambil alih, posisi & parent-nya
     * tetap). Belum ada → dibuat paling atas, agar tidak didahului queue lain yang targetnya satu subnet.
     *
     * @param  list<array{id: string, name: string, target: string, comment: ?string}>  $queues
     */
    private function terapkanQueue(MikrotikGateway $gw, Pelanggan $pelanggan, array $queues): string
    {
        $ip = $this->ipPelanggan($gw, $pelanggan);
        $atribut = [
            'name' => trim("{$pelanggan->id_pelanggan} {$pelanggan->nama}"),
            'target' => "$ip/32",
            ...$this->limitQueue((string) $pelanggan->paket?->rate_limit),
            'comment' => (string) $pelanggan->id_pelanggan,
        ];
        $queue = array_find($queues, fn (array $q) => $q['comment'] === $pelanggan->id_pelanggan)
            ?? array_find($queues, fn (array $q) => in_array($q['target'], [$ip, "$ip/32"], true));
        if ($queue) {
            $gw->setQueue($queue['id'], $atribut);

            return "Queue {$atribut['name']} diperbarui ({$atribut['max-limit']})";
        }
        $gw->addQueue($atribut, $queues[0]['id'] ?? null);

        return "Queue {$atribut['name']} dibuat ({$atribut['max-limit']})";
    }

    /**
     * Rate limit paket berformat PPP ("rx/tx [burst rx/tx [threshold rx/tx [waktu burst [prioritas [limit-at]]]]]",
     * mis. 10M/10M 20M/20M 8M/8M 16/16 8) menjadi atribut Simple Queue. Urutan rx/tx PPP = upload/download pelanggan,
     * sama dengan Simple Queue. Nilai tunggal dipakai untuk upload & download.
     *
     * @return array<string, string>
     */
    private function limitQueue(string $rateLimit): array
    {
        $kolom = ['max-limit', 'burst-limit', 'burst-threshold', 'burst-time', 'priority', 'limit-at'];
        $nilai = array_slice(preg_split('/\s+/', trim($rateLimit)) ?: [], 0, count($kolom));

        return array_combine(array_slice($kolom, 0, count($nilai)), array_map(fn (string $v) => str_contains($v, '/') ? $v : "$v/$v", $nilai));
    }

    /** IP pelanggan di billing; IP Binding tanpa IP memakai address IP Binding di router. */
    private function ipPelanggan(MikrotikGateway $gw, Pelanggan $pelanggan): string
    {
        $ip = $pelanggan->ip_address
            ?: ($pelanggan->jns_koneksi === 'ip_binding' ? $this->akunDiRouter($gw, $pelanggan)['address'] : null);

        return $ip ?: throw new RouterOsException("IP address {$pelanggan->nama} belum diisi, edit data pelanggan dulu");
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

    /** @return array{id: string, name: string, profile: string, address: ?string} */
    private function akunDiRouter(MikrotikGateway $gw, Pelanggan $pelanggan): array
    {
        $nama = $pelanggan->akunKoneksi();

        return $gw->findAccount($pelanggan->jns_koneksi, $nama)
            ?? throw new RouterOsException(self::AKUN[$pelanggan->jns_koneksi]." '$nama' tidak ditemukan di router {$pelanggan->router->nama_router}");
    }

    /** @return array<int, string> */
    private function namaProfile(MikrotikGateway $gw, string $jenis): array
    {
        return array_column($gw->profileList($jenis), 'name');
    }

    private function pastikanProfileAda(MikrotikGateway $gw, string $jenis, string $profile, string $label): void
    {
        if (! in_array($profile, $this->namaProfile($gw, $jenis), true)) {
            throw new RouterOsException("$label '$profile' belum ada di router, buat dulu di ".self::LEWAT_PROFILE[$jenis]);
        }
    }

    private function log(Pelanggan $pelanggan, string $aksi, ?string $dari, ?string $ke, bool $berhasil, string $pesan): void
    {
        IsolirLog::create([
            'router_id' => $pelanggan->router_id, 'pelanggan_id' => $pelanggan->id, 'username' => $pelanggan->akunKoneksi() ?? '-',
            'aksi' => $aksi, 'profile_dari' => $dari, 'profile_ke' => $ke, 'berhasil' => $berhasil, 'pesan' => $pesan,
        ]);
    }
}
