<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use App\Models\PembayaranInstansi;
use App\Models\SetoranMitra;
use App\Models\User;
use Database\Seeders\LaporanUjiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LaporanUjiSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-04 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    #[TestWith(['reseller', 'bulanan', 1, 1, 1])]
    #[TestWith(['korporat', 'bulanan', 1, 1, 1])]
    #[TestWith(['pemerintah', 'bulanan', 1, 2, 1])]
    #[TestWith(['retail', 'bulanan', 4, 0, 7])]
    #[TestWith(['retail', 'tahunan', 4, 4, 3])]
    #[TestWith(['korporat', 'tahunan', 1, 3, 0])]
    public function test_every_type_has_paid_partial_and_unpaid_customers(string $tipe, string $mode, int $lunas, int $sebagian, int $belum): void
    {
        $this->seed(LaporanUjiSeeder::class);

        $this->get("/laporan?tipe={$tipe}&mode={$mode}&tahun=2026")->assertOk()->assertInertia(fn ($page) => $page
            ->where('ringkasan.lunas', $lunas)->where('ringkasan.sebagian', $sebagian)->where('ringkasan.belum_lunas', $belum));
    }

    public function test_seeder_can_be_run_twice_without_duplicates(): void
    {
        $jumlah = fn () => [Pembayaran::count(), SetoranMitra::count(), PembayaranInstansi::count()];
        $this->seed(LaporanUjiSeeder::class);
        $sebelum = $jumlah();

        $this->seed(LaporanUjiSeeder::class);

        $this->assertSame($sebelum, $jumlah());
        $this->get('/laporan')->assertInertia(fn ($page) => $page->has('perTipe', 4)->where('ringkasan.jumlah', 21));
    }

    public function test_seeder_does_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->seed(LaporanUjiSeeder::class);

        $this->assertDatabaseCount('pelanggan_instansi', 0);
    }
}
