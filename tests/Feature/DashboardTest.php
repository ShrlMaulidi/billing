<?php

namespace Tests\Feature;

use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-15 10:00:00');
    }

    public function test_dashboard_shows_database_customers_and_zero_billing_without_dummy_data(): void
    {
        $paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '20M']);
        $this->pelanggan('baru', ['tgl_registrasi' => '2026-10-01', 'id_paket' => $paket->id]);
        $this->pelanggan('lama', ['tgl_registrasi' => '2026-09-30', 'status_langganan' => 'off']);
        $this->pelanggan('stop', ['tgl_registrasi' => '2026-10-02', 'status' => 'berhenti']);

        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard/Index')
            ->where('kpi', ['total' => 2, 'lunas' => 0, 'belum_lunas' => 0, 'on' => 1, 'off' => 1, 'berhenti' => 1, 'pemasukan_bulan' => 0, 'pemasukan_hari' => 0])
            ->has('pelangganBaru', 2)
            ->where('pelangganBaru.0.username_pppoe', 'stop')
            ->where('pelangganBaru.1.paket', 'Home 20 Mbps')
            ->where('pelangganBaru.1.harga', 200000)
            ->where('wagw', ['devices' => [], 'sent' => 0, 'outbox' => 0, 'failed' => 0])
            ->has('grafik', 12)
            ->where('grafik.0', ['periode' => '2025-11-01', 'total' => 0])
            ->where('grafik.11', ['periode' => '2026-10-01', 'total' => 0]));
    }

    public function test_customer_list_filters_database_customers_by_category(): void
    {
        $this->pelanggan('baru', ['tgl_registrasi' => '2026-10-01']);
        $this->pelanggan('lama', ['tgl_registrasi' => '2026-09-30']);
        $this->pelanggan('stop', ['tgl_registrasi' => '2026-08-01', 'status' => 'berhenti']);

        $this->get('/dashboard/pelanggan')->assertInertia(fn ($page) => $page->has('rows', 2)->where('rows.0.username_pppoe', 'baru')->where('rows.1.username_pppoe', 'lama'));
        $this->get('/dashboard/pelanggan?kategori=baru')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.username_pppoe', 'baru'));
        $this->get('/dashboard/pelanggan?kategori=berhenti')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.username_pppoe', 'stop'));
        $this->get('/dashboard/pelanggan?kategori=lunas')->assertInertia(fn ($page) => $page->has('rows', 0));
    }

    public function test_branch_dashboard_and_income_summary_are_empty_and_branch_detail_returns_404(): void
    {
        $this->get('/dashboard-cabang')->assertOk()->assertInertia(fn ($page) => $page->component('DashboardCabang/Index')->has('rows', 0));
        $this->get('/income-summary')->assertOk()->assertInertia(fn ($page) => $page->component('IncomeSummary/Index')
            ->where('total', ['total' => 0, 'jumlah' => 0])->has('metode', 5)->where('metode.0.total', 0)->has('rekening', 0)->has('pembayaran', 0));

        $this->get('/dashboard-cabang/1')->assertNotFound();
    }

    private function pelanggan(string $username, array $attrs = []): Pelanggan
    {
        return Pelanggan::create(['nama' => ucfirst($username), 'username_pppoe' => $username, ...$attrs]);
    }
}
