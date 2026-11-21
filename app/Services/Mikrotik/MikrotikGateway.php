<?php

namespace App\Services\Mikrotik;

/** Operasi Mikrotik yang dipakai billing (dipisah agar mudah diganti tiruan saat test). */
interface MikrotikGateway
{
    /** @return array{identity: string, version: string, board: string, uptime: string} */
    public function info(): array;

    /**
     * Akun koneksi berdasarkan username (MAC address untuk IP Binding): pppoe → PPP Secret, hotspot → Hotspot User,
     * ip_binding → Hotspot IP Binding. profile = type untuk IP Binding; address = remote address PPP Secret atau
     * address Hotspot User / IP Binding.
     *
     * @return array{id: string, name: string, profile: string, address: ?string}|null
     */
    public function findAccount(string $jenis, string $name): ?array;

    /**
     * Profile paket yang ada di router ($jenis pppoe → PPP Profile, hotspot → Hotspot User Profile).
     *
     * @return list<array{name: string, rate_limit: ?string}>
     */
    public function profileList(string $jenis): array;

    /**
     * Ubah atribut akun koneksi, mis. ['profile' => '20Mbps', 'remote-address' => '10.12.10.5'].
     *
     * @param  array<string, string>  $attributes
     */
    public function setAccount(string $jenis, string $id, array $attributes): void;

    /**
     * Putus sesi aktif ($jenis pppoe → PPP Active, hotspot → Hotspot Active) agar profile baru langsung berlaku;
     * mengembalikan jumlah sesi yang diputus.
     */
    public function removeActive(string $name, string $jenis = 'pppoe'): int;

    /**
     * Buat atau perbarui profile paket ($jenis pppoe → PPP Profile, hotspot → Hotspot User Profile).
     *
     * @param  array<string, string>  $attributes  mis. ['rate-limit' => '10M/10M', 'remote-address' => 'pool-10m']
     * @return bool true bila profile baru dibuat
     */
    public function saveProfile(string $jenis, string $name, array $attributes): bool;

    /** @return list<array{name: string, ranges: string}> IP Pool di router */
    public function pools(): array;

    /** @return list<array{name: string, address: ?string}> sesi PPP yang sedang aktif beserta IP-nya */
    public function activeSessions(): array;

    /** Buat atau perbarui IP Pool; true bila baru dibuat. */
    public function savePool(string $name, string $ranges): bool;

    /**
     * Akun koneksi di router: pppoe → PPP Secret, hotspot → Hotspot User, ip_binding → Hotspot IP Binding.
     * name = username (atau MAC address untuk IP Binding), profile = profile (atau type untuk IP Binding).
     *
     * @return list<array{name: string, profile: ?string, address: ?string, comment: ?string}>
     */
    public function accounts(string $jenis): array;

    /**
     * Tambah akun koneksi; atribut mengikuti nama field di menu router, mis. ['name' => 'budi', 'password' => '...', 'profile' => '10M'].
     *
     * @param  array<string, string>  $attributes
     */
    public function addAccount(string $jenis, array $attributes): void;

    /** Apakah $address sudah ada (entri statis) di firewall address-list $list. */
    public function inAddressList(string $list, string $address): bool;

    public function addAddressList(string $list, string $address, string $comment): void;

    /** Hapus entri statis $address dari address-list $list; mengembalikan jumlah entri yang dihapus. */
    public function removeAddressList(string $list, string $address): int;
}
