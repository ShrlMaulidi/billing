<?php

namespace Tests\Feature;

use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagihanProrateTest extends TestCase
{
    use RefreshDatabase;

    private Paket $paket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-25 10:00:00');
        $this->actingAs(User::factory()->create());
        $this->paket = Paket::create(['nama_paket' => 'Home 10 Mbps', 'harga' => 150000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '10M']);
    }

    public function test_prepaid_prorate_bill_appears_in_registration_month_and_is_paid_with_prorated_amount(): void
    {
        $budi = $this->pelanggan('budi', ['tgl_registrasi' => '2026-10-20']);
        $this->pelanggan('full', ['tgl_registrasi' => '2026-10-20', 'skema_tagihan' => 'full']);
        $this->pelanggan('awal', ['tgl_registrasi' => '2026-10-01']);

        // 12 dari 31 hari × 150.000 = 58.064,5 → dibulatkan ke ribuan ke atas
        $this->get('/pelanggan/prorate')->assertOk()->assertInertia(fn ($page) => $page->where('status', 'prorate')->has('rows', 1)->where('jumlah.prorate', 1)
            ->where('rows.0.username_pppoe', 'budi')
            ->where('rows.0.prorate', ['periode' => '2026-10-01', 'hari' => 12, 'hari_bulan' => 31, 'nominal' => 59000, 'muncul' => '2026-10-20']));

        $this->bayar($budi, 10)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'sebesar Rp59.000'));
        $this->bayar($budi, 11)->assertOk();

        $this->assertSame([[59000, true], [150000, false]], Pembayaran::orderBy('periode')->get()->map(fn ($p) => [$p->harga, $p->is_prorate])->all());
        $this->get('/pelanggan/prorate')->assertInertia(fn ($page) => $page->has('rows', 0));
    }

    public function test_postpaid_prorate_bill_appears_on_the_first_of_next_month(): void
    {
        $this->pelanggan('sari', ['tgl_registrasi' => '2026-10-19', 'jns_tagihan' => 'pascabayar']);

        $this->get('/pelanggan/prorate')->assertInertia(fn ($page) => $page->has('rows', 0));

        $this->travelTo('2026-11-01 08:00:00');
        $this->get('/pelanggan/prorate')->assertInertia(fn ($page) => $page->has('rows', 1)
            ->where('rows.0.prorate.muncul', '2026-11-01')->where('rows.0.prorate.nominal', 63000));
    }

    public function test_postpaid_customer_is_billed_for_previous_month_usage(): void
    {
        $sari = $this->pelanggan('sari', ['tgl_registrasi' => '2026-10-01', 'jns_tagihan' => 'pascabayar', 'skema_tagihan' => 'full']);

        // Oktober: pemakaian Oktober baru ditagih 1 November
        $this->get('/pelanggan/belum-lunas?bulan=10&tahun=2026')->assertInertia(fn ($page) => $page->has('rows', 0));
        $this->get('/pelanggan/belum-lunas?bulan=11&tahun=2026')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.periode_tagihan', '2026-10-01'));

        $this->bayar($sari, 10)->assertOk();

        $this->get('/pelanggan/lunas?bulan=11&tahun=2026')->assertInertia(fn ($page) => $page->has('rows', 1)
            ->where('rows.0.pembayaran_periode.no_invoice', Pembayaran::sole()->no_invoice));
    }

    public function test_stopped_customers_page_lists_only_stopped_customers(): void
    {
        $this->pelanggan('aktif', ['skema_tagihan' => 'full']);
        $this->pelanggan('stop', ['skema_tagihan' => 'full', 'status' => 'berhenti']);

        $this->get('/pelanggan/berhenti')->assertOk()->assertInertia(fn ($page) => $page->where('status', 'berhenti')
            ->has('rows', 1)->where('rows.0.username_pppoe', 'stop')->where('jumlah.berhenti', 1));
    }

    private function pelanggan(string $username, array $attrs = []): Pelanggan
    {
        return Pelanggan::create(['nama' => ucfirst($username), 'username_pppoe' => $username, 'id_paket' => $this->paket->id, 'skema_tagihan' => 'prorate', ...$attrs]);
    }

    private function bayar(Pelanggan $pelanggan, int $bulan)
    {
        return $this->postJson("/pelanggan/{$pelanggan->id}/bayar", ['bulan' => $bulan, 'tahun' => 2026, 'metode' => 'cash']);
    }
}
