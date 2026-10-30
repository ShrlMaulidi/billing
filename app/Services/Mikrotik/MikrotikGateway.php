<?php

namespace App\Services\Mikrotik;

/** Operasi Mikrotik yang dipakai billing (dipisah agar mudah diganti tiruan saat test). */
interface MikrotikGateway
{
    /** @return array{identity: string, version: string, board: string, uptime: string} */
    public function info(): array;

    /** @return array{id: string, name: string, profile: string, service: string, remote_address: ?string, comment: ?string, disabled: bool}|null */
    public function findSecret(string $name): ?array;

    /** @return array<int, string> nama PPP profile */
    public function profiles(): array;

    public function setSecretProfile(string $id, string $profile): void;

    /** Putus sesi PPP aktif agar profile baru langsung berlaku; mengembalikan jumlah sesi yang diputus. */
    public function removeActive(string $name): int;

    /**
     * Buat atau perbarui profile paket ($jenis pppoe → PPP Profile, hotspot → Hotspot User Profile).
     *
     * @param  array<string, string>  $attributes  mis. ['rate-limit' => '10M/10M', 'remote-address' => 'pool-10m']
     * @return bool true bila profile baru dibuat
     */
    public function saveProfile(string $jenis, string $name, array $attributes): bool;

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
}
