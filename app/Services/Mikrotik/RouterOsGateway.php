<?php

namespace App\Services\Mikrotik;

class RouterOsGateway implements MikrotikGateway
{
    /** Menu router untuk profile paket tiap jenis koneksi. */
    private const PROFILE = ['pppoe' => '/ppp/profile', 'hotspot' => '/ip/hotspot/user/profile'];

    /** Menu router untuk tiap jenis akun koneksi pelanggan. */
    private const AKUN = ['pppoe' => '/ppp/secret', 'hotspot' => '/ip/hotspot/user', 'ip_binding' => '/ip/hotspot/ip-binding'];

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

    public function findSecret(string $name): ?array
    {
        $row = $this->api->command('/ppp/secret/print', [], ['name' => $name])[0] ?? null;

        return $row ? $this->secret($row) : null;
    }

    public function profiles(): array
    {
        return array_values(array_filter(array_map(fn ($row) => $row['name'] ?? null, $this->api->command('/ppp/profile/print'))));
    }

    public function profileList(string $jenis): array
    {
        $rows = array_filter($this->api->command(self::PROFILE[$jenis].'/print'), fn ($row) => isset($row['name']));

        return array_values(array_map(fn ($row) => ['name' => $row['name'], 'rate_limit' => $row['rate-limit'] ?? null], $rows));
    }

    public function setSecretProfile(string $id, string $profile): void
    {
        $this->api->command('/ppp/secret/set', ['.id' => $id, 'profile' => $profile]);
    }

    public function removeActive(string $name): int
    {
        $sessions = $this->api->command('/ppp/active/print', [], ['name' => $name]);
        foreach ($sessions as $session) {
            $this->api->command('/ppp/active/remove', ['.id' => $session['.id']]);
        }

        return count($sessions);
    }

    public function saveProfile(string $jenis, string $name, array $attributes): bool
    {
        return $this->upsert(self::PROFILE[$jenis], $name, $attributes);
    }

    public function savePool(string $name, string $ranges): bool
    {
        return $this->upsert('/ip/pool', $name, ['ranges' => $ranges]);
    }

    public function accounts(string $jenis): array
    {
        return array_map(fn ($row) => [
            'name' => $row['name'] ?? $row['mac-address'] ?? '',
            'profile' => $row['profile'] ?? $row['type'] ?? null,
            'address' => $row['address'] ?? $row['remote-address'] ?? null,
            'comment' => $row['comment'] ?? null,
        ], $this->api->command(self::AKUN[$jenis].'/print'));
    }

    public function addAccount(string $jenis, array $attributes): void
    {
        $this->api->command(self::AKUN[$jenis].'/add', $attributes);
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

    private function secret(array $row): array
    {
        return [
            'id' => $row['.id'] ?? '',
            'name' => $row['name'] ?? '',
            'profile' => $row['profile'] ?? 'default',
            'service' => $row['service'] ?? 'any',
            'remote_address' => $row['remote-address'] ?? null,
            'comment' => $row['comment'] ?? null,
            'disabled' => ($row['disabled'] ?? 'false') === 'true',
        ];
    }
}
