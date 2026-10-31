<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Rekening;
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

    public function test_dashboard_shows_database_customers_and_unpaid_count_without_payments(): void
    {
        $paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '20M']);
        $this->pelanggan('baru', ['tgl_registrasi' => '2026-10-01', 'id_paket' => $paket->id]);
        $this->pelanggan('lama', ['tgl_registrasi' => '2026-09-30', 'status_langganan' => 'off']);
        $this->pelanggan('stop', ['tgl_registrasi' => '2026-10-02', 'status' => 'berhenti']);

        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard/Index')
            ->where('kpi', ['total' => 2, 'lunas' => 0, 'belum_lunas' => 1, 'on' => 1, 'off' => 1, 'berhenti' => 1, 'pemasukan_bulan' => 0, 'pemasukan_hari' => 0])
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

    public function test_branch_dashboard_lists_branches_with_customer_counts_and_current_rates(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $garut->pajak()->create([
            'periode' => '2026-01-01', 'ppn_persen' => 11, 'pph_persen' => 0.5, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 2500,
            'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 30,
        ]);
        $this->pelanggan('baru', ['id_lokasi' => $garut->id, 'tgl_registrasi' => '2026-10-01']);
        $this->pelanggan('lama', ['id_lokasi' => $garut->id, 'tgl_registrasi' => '2026-09-30']);
        $this->pelanggan('stop', ['id_lokasi' => $garut->id, 'tgl_registrasi' => '2026-10-02', 'status' => 'berhenti']);

        $this->get('/dashboard-cabang')->assertOk()->assertInertia(fn ($page) => $page->component('DashboardCabang/Index')->has('rows', 2)
            ->where('rows.0.nama_cabang', 'MSS Garut')->where('rows.0.jumlah_pelanggan', 2)->where('rows.0.pelanggan_baru', 2)
            ->where('rows.0.ppn_persen', 11)->where('rows.0.admin_fee_label', 'Rp2.500/trx')->where('rows.0.metode_bagi_hasil', 'Persentase 30%')
            ->where('rows.0.pemasukan', 0)
            ->where('rows.1.nama_cabang', 'MSS Cimahi')->where('rows.1.jumlah_pelanggan', 0)->where('rows.1.ppn_persen', null));
        $this->get("/dashboard-cabang/{$garut->id}")->assertOk()->assertInertia(fn ($page) => $page->component('DashboardCabang/Detail')
            ->where('lokasi.nama_lokasi', 'MSS Garut')->where('rekap.jumlah_pelanggan', 2)->has('pelanggan', 3)->has('pembayaran', 0));
        $this->get('/dashboard-cabang/999')->assertNotFound();
    }

    public function test_branch_user_only_sees_own_branch_on_branch_dashboard(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $this->actingAs(User::factory()->peran('Mitra')->create(['id_lokasi' => $garut->id]));

        $this->get("/dashboard-cabang?cabang={$cimahi->id}")->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama_cabang', 'MSS Garut'));
        $this->get("/dashboard-cabang/{$cimahi->id}")->assertNotFound();
    }

    public function test_mitra_dashboard_shows_balance_of_own_branch_only(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut', 'nama_mitra' => 'Mitra Garut']);
        $cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);

        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('saldoMitra', null));

        $this->actingAs(User::factory()->peran('Teknisi')->create(['id_lokasi' => $garut->id]));
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('saldoMitra', null));

        $this->actingAs(User::factory()->peran('Mitra')->create(['id_lokasi' => $garut->id]));
        $this->bayar($cimahi, 500000, 'cash');
        $this->get("/dashboard?cabang={$cimahi->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('saldoMitra.nama_cabang', 'MSS Garut')->where('saldoMitra.nama_mitra', 'Mitra Garut')
            ->where('saldoMitra.pemasukan', 0)->where('saldoMitra.saldo', 0));
    }

    public function test_branch_recap_follows_withdrawal_scheme(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $garut->pajak()->create([
            'periode' => '2026-01-01', 'ppn_persen' => 11, 'pph_persen' => 2.5, 'admin_fee_tipe' => 'persentase', 'admin_fee' => 5,
            'metode_bagi_hasil' => 'nominal', 'nilai_bagi_hasil' => 4830000,
        ]);
        $pusat = Rekening::create(['nama_rekening' => 'BCA', 'atas_nama' => 'MSS', 'no_rekening' => '111']);
        $cabang = Rekening::create(['nama_rekening' => 'BRI', 'atas_nama' => 'Mitra', 'no_rekening' => '222', 'id_lokasi' => $garut->id]);
        $this->bayar($garut, 11000000, 'cash');
        $this->bayar($garut, 330000, 'transfer', $cabang);
        $this->bayar($garut, 170000, 'transfer', $pusat);
        $this->bayar($garut, 999000, 'cash', paidAt: '2026-09-30 23:00:00');

        // Angka sama dengan Ringkasan Bulan Ini di aplikasi lama (omset Rp11.500.000)
        $this->get("/dashboard-cabang/{$garut->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('rekap.pemasukan', 11500000)->where('rekap.dpp', 10360360)->where('rekap.ppn', 1139640)
            ->where('rekap.admin_fee', 518018)->where('rekap.nominal_bagi_hasil', 4830000)->where('rekap.omset_reseller', 5012342)
            ->where('rekap.pph', 125309)->where('rekap.harus_setor', 6612966)->where('rekap.pendapatan_reseller', 4887034)
            ->where('rekap.pemasukan_via_mitra', 11330000)->where('rekap.total_cash', 11000000)->where('rekap.sisa_setor', 6612966)
            ->where('rekap.saldo', -6442966));
    }

    public function test_income_summary_is_empty_until_payments_exist(): void
    {
        $this->get('/income-summary')->assertOk()->assertInertia(fn ($page) => $page->component('IncomeSummary/Index')
            ->where('total', ['total' => 0, 'jumlah' => 0])->has('metode', 5)->where('metode.0.total', 0)->has('rekening', 0)->has('pembayaran', 0));
    }

    private function bayar(Lokasi $lokasi, int $nominal, string $metode, ?Rekening $rekening = null, string $paidAt = '2026-10-10 09:00:00'): Pembayaran
    {
        return Pembayaran::create([
            'id_lokasi' => $lokasi->id, 'periode' => '2026-10-01', 'nama_pelanggan' => 'Pelanggan', 'nama_paket' => 'Paket', 'harga' => $nominal,
            'jml_bayar' => $nominal, 'metode' => $metode, 'rekening_id' => $rekening?->id, 'paid_at' => $paidAt,
        ]);
    }

    private function pelanggan(string $username, array $attrs = []): Pelanggan
    {
        return Pelanggan::create(['nama' => ucfirst($username), 'username_pppoe' => $username, ...$attrs]);
    }
}
