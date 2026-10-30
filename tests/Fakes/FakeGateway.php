<?php

namespace Tests\Fakes;

use App\Services\Mikrotik\MikrotikGateway;

/** Mikrotik tiruan: menyimpan PPP Secret di memori dan mencatat sesi yang diputus. */
class FakeGateway implements MikrotikGateway
{
    public array $secrets = [
        'budi' => ['id' => '*1', 'name' => 'budi', 'profile' => '20Mbps', 'service' => 'pppoe', 'remote_address' => null, 'comment' => null, 'disabled' => false],
        'sari' => ['id' => '*2', 'name' => 'sari', 'profile' => '10Mbps', 'service' => 'pppoe', 'remote_address' => null, 'comment' => null, 'disabled' => false],
        'tono' => ['id' => '*3', 'name' => 'tono', 'profile' => 'ISOLIR', 'service' => 'pppoe', 'remote_address' => null, 'comment' => null, 'disabled' => false],
    ];

    public array $profiles = ['default', '10Mbps', '20Mbps', 'ISOLIR'];

    public array $active = ['budi'];

    public array $kicked = [];

    public function info(): array
    {
        return ['identity' => 'MSS-Pusat', 'version' => '7.16', 'board' => 'CCR1036', 'uptime' => '3d4h'];
    }

    public function findSecret(string $name): ?array
    {
        return $this->secrets[$name] ?? null;
    }

    public function profiles(): array
    {
        return $this->profiles;
    }

    public function setSecretProfile(string $id, string $profile): void
    {
        foreach ($this->secrets as $name => $s) {
            if ($s['id'] === $id) {
                $this->secrets[$name]['profile'] = $profile;
            }
        }
    }

    public function removeActive(string $name): int
    {
        if (! in_array($name, $this->active, true)) {
            return 0;
        }
        $this->active = array_values(array_diff($this->active, [$name]));
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

    public function savePool(string $name, string $ranges): bool
    {
        $baru = ! isset($this->pools[$name]);
        $this->pools[$name] = $ranges;

        return $baru;
    }
}
