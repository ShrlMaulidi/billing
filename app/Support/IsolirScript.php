<?php

namespace App\Support;

/**
 * Template script isolir MikroTik (RouterOS v6 & v7) untuk metode Static, PPPoE, dan Hotspot.
 *
 * Pelanggan isolir = IP di address-list ISOLIR (PPPoE/Hotspot otomatis lewat profile isolir, Static manual).
 * HTTP diarahkan lewat web proxy ke halaman isolir billing; lalu lintas lain diblokir kecuali DNS
 * dan alamat di ISOLIR-ALLOW. Semua aturan diberi comment TAG sehingga aman di-paste ulang.
 */
class IsolirScript
{
    private const TAG = 'isolir-mss';

    /**
     * @param  array{profile: string, jaringan: string, port_proxy: int, url_isolir: string, alamat_izin: array<int, string>}  $opsi
     */
    public function __construct(private array $opsi) {}

    /** @return array{static: string, pppoe: string, hotspot: string} */
    public function semua(): array
    {
        return ['static' => $this->statik(), 'pppoe' => $this->pppoe(), 'hotspot' => $this->hotspot()];
    }

    public function statik(): string
    {
        return $this->gabung([
            ...$this->judul('Static (IP statis)'),
            ...$this->dasar(),
            '',
            '# 5. Isolir pelanggan statis: tambahkan IP-nya ke address-list ISOLIR. Aktifkan kembali = hapus dari list.',
            '# /ip firewall address-list add list=ISOLIR address=IP_PELANGGAN comment="NAMA_PELANGGAN"',
            '# /ip firewall address-list remove [find list=ISOLIR address=IP_PELANGGAN]',
        ]);
    }

    public function pppoe(): string
    {
        [$gateway, $range, $cidr] = $this->jaringan();
        $profile = $this->q($this->opsi['profile']);

        return $this->gabung([
            ...$this->judul('PPPoE'),
            ...$this->dasar(),
            '',
            '# 5. IP pool & PPP profile isolir (nama profile = "Profile Isolir" di menu Router billing)',
            $this->upsert('/ip pool', $profile, "ranges=$range"),
            $this->upsert('/ppp profile', $profile, "local-address=$gateway remote-address=$profile address-list=ISOLIR"),
            "/ip firewall nat add chain=srcnat src-address=$cidr action=masquerade comment=\"".self::TAG.'"',
        ]);
    }

    public function hotspot(): string
    {
        [, $range, $cidr] = $this->jaringan();
        $profile = $this->q($this->opsi['profile']);

        return $this->gabung([
            ...$this->judul('Hotspot'),
            ...$this->dasar(),
            '',
            '# 5. IP pool & Hotspot user profile isolir; pindahkan user hotspot ke profile ini untuk mengisolir',
            $this->upsert('/ip pool', $profile, "ranges=$range"),
            $this->upsert('/ip hotspot user profile', $profile, "address-pool=$profile address-list=ISOLIR"),
            "/ip firewall nat add chain=srcnat src-address=$cidr action=masquerade comment=\"".self::TAG.'"',
        ]);
    }

    /** @return array<int, string> */
    private function judul(string $metode): array
    {
        return [
            "# ===== Template isolir MSS - $metode =====",
            '# Paste di New Terminal MikroTik. Aman di-paste ulang (aturan lama ber-comment "'.self::TAG.'" dihapus dulu).',
            '# Halaman isolir: '.$this->opsi['url_isolir'],
            '',
        ];
    }

    /**
     * Bagian yang sama untuk semua metode: alamat yang diizinkan, web proxy, redirect HTTP, dan blokir.
     *
     * @return array<int, string>
     */
    private function dasar(): array
    {
        $port = $this->opsi['port_proxy'];
        $hostBilling = parse_url($this->opsi['url_isolir'], PHP_URL_HOST);
        // redirect-to web proxy ditulis tanpa skema (RouterOS menambahkan http://)
        $tujuan = preg_replace('#^https?://#', '', $this->opsi['url_isolir']);
        $tag = 'comment="'.self::TAG.'"';

        $izin = array_map(
            fn ($alamat) => "/ip firewall address-list add list=ISOLIR-ALLOW address={$this->q($alamat)} $tag",
            array_values(array_unique([$hostBilling, ...$this->opsi['alamat_izin']])),
        );
        // Urutan akhir di firewall: accept izin & DNS → reject TCP → drop sisanya; proxy hanya untuk pelanggan isolir
        $blokir = [
            'chain=forward src-address-list=ISOLIR dst-address-list=ISOLIR-ALLOW action=accept',
            'chain=forward src-address-list=ISOLIR protocol=udp dst-port=53 action=accept',
            'chain=forward src-address-list=ISOLIR protocol=tcp dst-port=53 action=accept',
            'chain=forward src-address-list=ISOLIR protocol=tcp action=reject reject-with=tcp-reset',
            'chain=forward src-address-list=ISOLIR action=drop',
            "chain=input src-address-list=ISOLIR protocol=tcp dst-port=$port action=accept",
            "chain=input protocol=tcp dst-port=$port action=drop",
        ];

        return [
            '# 1. Alamat yang tetap boleh diakses pelanggan isolir (billing + tambahan)',
            "/ip firewall address-list remove [find $tag]",
            ...$izin,
            '',
            '# 2. Web proxy: semua halaman HTTP diarahkan ke halaman isolir',
            "/ip proxy set enabled=yes port=$port",
            "/ip proxy access remove [find $tag]",
            "/ip proxy access add dst-host={$this->q($hostBilling)} action=allow $tag",
            "/ip proxy access add action=deny redirect-to={$this->q($tujuan)} $tag",
            '',
            '# 3. Arahkan HTTP pelanggan isolir ke web proxy',
            "/ip firewall nat remove [find $tag]",
            $this->diAtas('/ip firewall nat', "chain=dstnat src-address-list=ISOLIR dst-address-list=!ISOLIR-ALLOW protocol=tcp dst-port=80 action=redirect to-ports=$port"),
            '',
            '# 4. Blokir akses lain pelanggan isolir (HTTPS, aplikasi, game) kecuali DNS & alamat yang diizinkan',
            "/ip firewall filter remove [find $tag]",
            // Tiap aturan disisipkan paling atas, jadi ditulis terbalik agar urutan akhirnya sesuai $blokir
            ...array_map(fn ($aturan) => $this->diAtas('/ip firewall filter', $aturan), array_reverse($blokir)),
        ];
    }

    /** Tambah aturan di atas aturan non-dinamis pertama (atau biasa bila daftar masih kosong). */
    private function diAtas(string $menu, string $aturan): string
    {
        $add = "$menu add $aturan comment=\"".self::TAG.'"';

        return "{ :local r [$menu find where dynamic=no]; :if ([:len \$r] > 0) do={ $add place-before=[:pick \$r 0] } else={ $add } }";
    }

    /** Add bila belum ada, set bila sudah ada (pool/profile tidak dihapus karena bisa sedang dipakai). */
    private function upsert(string $menu, string $nama, string $atribut): string
    {
        return ":if ([:len [$menu find name=$nama]] = 0) do={ $menu add name=$nama $atribut } else={ $menu set [find name=$nama] $atribut }";
    }

    /** @return array{0: string, 1: string, 2: string} gateway, range pool, CIDR jaringan */
    private function jaringan(): array
    {
        [$ip, $prefix] = explode('/', $this->opsi['jaringan']);
        $mask = (-1 << (32 - (int) $prefix)) & 0xFFFFFFFF;
        $network = ip2long($ip) & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        return [long2ip($network + 1), long2ip($network + 2).'-'.long2ip($broadcast - 1), long2ip($network)."/$prefix"];
    }

    private function q(string $nilai): string
    {
        return '"'.addcslashes($nilai, '"\\$').'"';
    }

    /** @param array<int, string> $baris */
    private function gabung(array $baris): string
    {
        return implode("\n", $baris);
    }
}
