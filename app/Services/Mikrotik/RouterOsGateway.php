<?php

namespace App\Services\Mikrotik;

class RouterOsGateway implements MikrotikGateway
{
    /** Menu router untuk profile paket tiap jenis koneksi. */
    private const PROFILE = ['pppoe' => '/ppp/profile', 'hotspot' => '/ip/hotspot/user/profile'];

    /** Menu router untuk tiap jenis akun koneksi pelanggan. */
    private const AKUN = ['pppoe' => '/ppp/secret', 'hotspot' => '/ip/hotspot/user', 'ip_binding' => '/ip/hotspot/ip-binding'];

    /** Menu sesi aktif & field username-nya untuk tiap jenis koneksi. */
    private const AKTIF = ['pppoe' => ['/ppp/active', 'name'], 'hotspot' => ['/ip/hotspot/active', 'user']];

    private const ADDRESS_LIST = '/ip/firewall/address-list';

    public function __construct(private RouterOsApi $api) {}

    public function info(): array
    {
        $identity = $this->api->command('/system/identity/print')[0] ?? [];
        $resource = $this->api->command('/system/resource/print')[0] ?? [];

        return [
            'identity' => $identity['name'] ?? '-',
            'version' => $resource['version'] ?? '-',
            'board' => $resource['board-name'] ?? '-',
            'uptime' => $resource['uptime'] ?? '-',
        ];
    }

    public function findAccount(string $jenis, string $name): ?array
    {
        $kunci = $jenis === 'ip_binding' ? 'mac-address' : 'name';
        $row = $this->api->command(self::AKUN[$jenis].'/print', [], [$kunci => $name])[0] ?? null;

        return $row ? ['id' => $row['.id'] ?? '', ...$this->akun($row), 'profile' => $row['profile'] ?? $row['type'] ?? 'default'] : null;
    }

    public function profileList(string $jenis): array
    {
        $rows = array_filter($this->api->command(self::PROFILE[$jenis].'/print'), fn ($row) => isset($row['name']));

        return array_values(array_map(fn ($row) => ['name' => $row['name'], 'rate_limit' => $row['rate-limit'] ?? null], $rows));
    }

    public function setAccount(string $jenis, string $id, array $attributes): void
    {
        $this->api->command(self::AKUN[$jenis].'/set', ['.id' => $id, ...$attributes]);
    }

    public function removeActive(string $name, string $jenis = 'pppoe'): int
    {
        [$path, $kunci] = self::AKTIF[$jenis];
        $sessions = $this->api->command("$path/print", [], [$kunci => $name]);
        foreach ($sessions as $session) {
            $this->api->command("$path/remove", ['.id' => $session['.id']]);
        }

        return count($sessions);
    }

    public function saveProfile(string $jenis, string $name, array $attributes): bool
    {
        return $this->upsert(self::PROFILE[$jenis], $name, $attributes);
    }

    public function pools(): array
    {
        $rows = array_filter($this->api->command('/ip/pool/print'), fn ($row) => isset($row['name']));

        return array_values(array_map(fn ($row) => ['name' => $row['name'], 'ranges' => $row['ranges'] ?? ''], $rows));
    }

    public function activeSessions(): array
    {
        return array_map(fn ($row) => ['name' => $row['name'] ?? '', 'address' => $row['address'] ?? null], $this->api->command('/ppp/active/print'));
    }

    public function savePool(string $name, string $ranges): bool
    {
        return $this->upsert('/ip/pool', $name, ['ranges' => $ranges]);
    }

    public function accounts(string $jenis): array
    {
        return array_map(fn ($row) => $this->akun($row), $this->api->command(self::AKUN[$jenis].'/print'));
    }

    public function addAccount(string $jenis, array $attributes): void
    {
        $this->api->command(self::AKUN[$jenis].'/add', $attributes);
    }

    public function inAddressList(string $list, string $address): bool
    {
        return $this->entriAddressList($list, $address) !== [];
    }

    public function addAddressList(string $list, string $address, string $comment): void
    {
        $this->api->command(self::ADDRESS_LIST.'/add', ['list' => $list, 'address' => $address, 'comment' => $comment]);
    }

    public function removeAddressList(string $list, string $address): int
    {
        $rows = $this->entriAddressList($list, $address);
        foreach ($rows as $row) {
            $this->api->command(self::ADDRESS_LIST.'/remove', ['.id' => $row['.id']]);
        }

        return count($rows);
    }

    /**
     * Entri statis saja; entri dinamis (dari profile PPP/Hotspot) dikelola router sendiri.
     *
     * @return array<int, array<string, string>>
     */
    private function entriAddressList(string $list, string $address): array
    {
        return $this->api->command(self::ADDRESS_LIST.'/print', [], ['list' => $list, 'address' => $address, 'dynamic' => 'false']);
    }

    /** @return array{name: string, profile: ?string, address: ?string, comment: ?string} */
    private function akun(array $row): array
    {
        return [
            'name' => $row['name'] ?? $row['mac-address'] ?? '',
            'profile' => $row['profile'] ?? $row['type'] ?? null,
            'address' => $row['address'] ?? $row['remote-address'] ?? null,
            'comment' => $row['comment'] ?? null,
        ];
    }

    /** Set bila item bernama $name sudah ada, selain itu add. */
    private function upsert(string $path, string $name, array $attributes): bool
    {
        $row = $this->api->command("$path/print", [], ['name' => $name])[0] ?? null;
        if ($row) {
            $this->api->command("$path/set", ['.id' => $row['.id'], ...$attributes]);

            return false;
        }
        $this->api->command("$path/add", ['name' => $name, ...$attributes]);

        return true;
    }
}
