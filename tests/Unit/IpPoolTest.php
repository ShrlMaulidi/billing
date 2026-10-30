<?php

namespace Tests\Unit;

use App\Support\IpPool;
use PHPUnit\Framework\TestCase;

class IpPoolTest extends TestCase
{
    public function test_counts_free_ips_across_ranges_and_cidr_without_network_and_broadcast(): void
    {
        $hasil = IpPool::kosong('10.12.10.2-10.12.10.4, 10.12.11.0/30', ['10.12.10.3', 'bukan-ip'], 3);

        $this->assertSame(['kosong' => ['10.12.10.2', '10.12.10.4', '10.12.11.1'], 'jumlah_kosong' => 4], $hasil);
    }

    public function test_invalid_ranges_are_ignored(): void
    {
        $this->assertSame(['kosong' => [], 'jumlah_kosong' => 0], IpPool::kosong('abc,10.0.0.9-10.0.0.1', []));
    }
}
