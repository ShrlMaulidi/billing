<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Pelanggan;
use Database\Seeders\DataUjiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataUjiSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_linked_test_data_and_can_be_run_twice(): void
    {
        $this->seed(DataUjiSeeder::class);
        $this->seed(DataUjiSeeder::class);

        $lokasi = Lokasi::firstWhere('kode_lokasi', 'DEMO01');
        $this->assertSame([1, 2, 1, 4, 6, 12], [
            Lokasi::count(), $lokasi->area()->count(), $lokasi->routers()->count(), $lokasi->paket()->count(),
            $lokasi->pelanggan()->count(), $lokasi->pajak()->count(),
        ]);
        $this->assertSame('off', Pelanggan::firstWhere('id_pelanggan', 'DEMO003')->status_langganan);
    }

    public function test_seeder_does_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->seed(DataUjiSeeder::class);

        $this->assertDatabaseCount('lokasi', 0);
    }
}
