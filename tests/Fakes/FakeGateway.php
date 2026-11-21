<?php

namespace Tests\Fakes;

use App\Services\Mikrotik\MikrotikGateway;

/** Mikrotik tiruan: menyimpan akun koneksi & address-list di memori dan mencatat sesi yang diputus. */
class FakeGateway implements MikrotikGateway
{
    public array $secrets = [
        'budi' => ['id' => '*1', 'name' => 'budi', 'profile' => '20Mbps', 'service' => 'pppoe', 'remote_address' => null, 'comment' => null, 'disabled' => false],
        'sari' => ['id' => '*2', 'name' => 'sari', 'profile' => '10Mbps', 'service' => 'pppoe', 'remote_address' => null, 'comment' => null, 'disabled' => false],
        'tono' => ['id' => '*3', 'name' => 'tono', 'profile' => 'ISOLIR', 'service' => 'pppoe', 'remote_address' => '10.12.10.3', 'comment' => null, 'disabled' => false],
    ];

    public array $profiles = ['default', '10Mbps', '20Mbps', 'ISOLIR'];

    public array $active = ['budi'];

    public array $kicked = [];

    public function info(): array
    {
        return ['identity' => 'MSS-Pusat', 'version' => '7.16', 'board' => 'CCR1036', 'uptime' => '3d4h'];
    }

    public function findAccount(string $jenis, string $name): ?array
    {
        if ($jenis === 'pppoe') {
            $s = $this->secrets[$name] ?? null;

            return $s ? ['id' => $s['id'], 'name' => $s['name'], 'profile' => $s['profile'], 'address' => $s['remote_address']] : null;
        }
        foreach ($this->akun[$jenis] as $i => $row) {
            if (($row['name'] ?? $row['mac-address']) === $name) {
                return ['id' => "$jenis:$i", 'name' => $name, 'profile' => $row['profile'] ?? $row['type'] ?? 'default', 'address' => $row['address'] ?? null];
            }
        }

        return null;
    }

    /** @var array<string, ?string> Hotspot User Profile di router: nama => rate-limit */
    public array $hotspotProfiles = ['default' => null, 'HS-1H' => '2M/2M'];

    public function profileList(string $jenis): array
    {
        $profiles = $jenis === 'hotspot'
            ? $this->hotspotProfiles
            : array_combine($this->profiles, array_map(fn ($name) => $this->savedProfiles[$name]['attributes']['rate-limit'] ?? ($name === '20Mbps' ? '20M/20M' : null), $this->profiles));

        return array_map(fn ($name, $rate) => ['name' => $name, 'rate_limit' => $rate], array_keys($profiles), $profiles);
    }

    public function setAccount(string $jenis, string $id, array $attributes): void
    {
        if ($jenis !== 'pppoe') {
            $i = (int) explode(':', $id)[1];
            $this->akun[$jenis][$i] = [...$this->akun[$jenis][$i], ...$attributes];

            return;
        }
        foreach ($this->secrets as $name => $s) {
            if ($s['id'] === $id) {
                $this->secrets[$name] = [...$s, ...array_filter(['profile' => $attributes['profile'] ?? null, 'remote_address' => $attributes['remote-address'] ?? null])];
            }
        }
    }

    /** @var list<string> username Hotspot yang sedang login */
    public array $hotspotActive = ['voucher1'];

    public function removeActive(string $name, string $jenis = 'pppoe'): int
    {
        $aktif = $jenis === 'hotspot' ? 'hotspotActive' : 'active';
        if (! in_array($name, $this->{$aktif}, true)) {
            return 0;
        }
        $this->{$aktif} = array_values(array_diff($this->{$aktif}, [$name]));
        $this->kicked[] = $name;

        return 1;
    }

    /** @var array<string, array{jenis: string, attributes: array<string, string>}> profile yang dibuat/diubah lewat saveProfile */
    public array $savedProfiles = [];

    /** @var array<string, string> nama pool => ranges */
    public array $pools = [];

    public function saveProfile(string $jenis, string $name, array $attributes): bool
    {
        $baru = ! in_array($name, $this->profiles, true);
        if ($baru) {
            $this->profiles[] = $name;
        }
        $this->savedProfiles[$name] = ['jenis' => $jenis, 'attributes' => $attributes];

        return $baru;
    }

    public function pools(): array
    {
        $rows = ['pool-pppoe' => '10.12.10.2-10.12.10.6', ...$this->pools];

        return array_map(fn ($name, $ranges) => ['name' => $name, 'ranges' => $ranges], array_keys($rows), $rows);
    }

    /** @var array<string, string> IP sesi aktif: username => address */
    public array $activeAddresses = ['budi' => '10.12.10.2'];

    public function activeSessions(): array
    {
        $aktif = array_intersect_key($this->activeAddresses, array_flip($this->active));

        return array_map(fn ($name, $address) => ['name' => $name, 'address' => $address], array_keys($aktif), $aktif);
    }

    public function savePool(string $name, string $ranges): bool
    {
        $baru = ! isset($this->pools[$name]);
        $this->pools[$name] = $ranges;

        return $baru;
    }

    /** @var array<string, list<array<string, string>>> Hotspot User & IP Binding di router (PPP Secret ada di) */
    public array $akun = [
        'hotspot' => [['name' => 'voucher1', 'profile' => 'HS-1H', 'comment' => 'Warung']],
        'ip_binding' => [['mac-address' => 'AA:BB:CC:DD:EE:01', 'address' => '192.168.10.5', 'type' => 'bypassed']],
    ];

    public function accounts(string $jenis): array
    {
        $rows = $jenis === 'pppoe' ? array_values($this->secrets) : $this->akun[$jenis];

        return array_map(fn ($row) => [
            'name' => $row['name'] ?? $row['mac-address'],
            'profile' => $row['profile'] ?? $row['type'] ?? null,
            'address' => $row['address'] ?? $row['remote_address'] ?? null,
            'comment' => $row['comment'] ?? null,
        ], $rows);
    }

    public function addAccount(string $jenis, array $attributes): void
    {
        if ($jenis === 'pppoe') {
            $this->secrets[$attributes['name']] = ['id' => '*'.(count($this->secrets) + 1), 'remote_address' => null, 'disabled' => false, 'comment' => null, ...$attributes];

            return;
        }
        $this->akun[$jenis][] = $attributes;
    }

    /** @var list<array{list: string, address: string, comment: string}> entri statis firewall address-list */
    public array $addressList = [];

    public function inAddressList(string $list, string $address): bool
    {
        return array_any($this->addressList, fn ($e) => $e['list'] === $list && $e['address'] === $address);
    }

    public function addAddressList(string $list, string $address, string $comment): void
    {
        $this->addressList[] = ['list' => $list, 'address' => $address, 'comment' => $comment];
    }

    public function removeAddressList(string $list, string $address): int
    {
        $sisa = array_values(array_filter($this->addressList, fn ($e) => $e['list'] !== $list || $e['address'] !== $address));
        $dihapus = count($this->addressList) - count($sisa);
        $this->addressList = $sisa;

        return $dihapus;
    }
}
