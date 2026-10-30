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

class PembayaranTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $lokasi;

    private Pelanggan $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $this->actingAs(User::factory()->create());
        $this->lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '20M']);
        $this->pelanggan = Pelanggan::create([
            'nama' => 'Budi', 'username_pppoe' => 'budi', 'id_lokasi' => $this->lokasi->id, 'id_paket' => $paket->id, 'tgl_registrasi' => '2026-08-01',
        ]);
    }

    public function test_cash_payment_uses_package_price_and_discount_computed_on_server(): void
    {
        $this->get('/pelanggan/belum-lunas')->assertInertia(fn ($page) => $page->has('rows', 1)->where('jumlah.belum_lunas', 1)
            ->where('rows.0.periode_lunas', [])->where('tahunBayar', range(2021, 2031)));

        // harga & jml_bayar palsu dari form diabaikan
        $this->bayar(['diskon_tipe' => 'nominal', 'diskon_nilai' => 25000, 'harga' => 1, 'jml_bayar' => 1])
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'periode Oktober 2026 sebesar Rp175.000'));

        $p = Pembayaran::sole();
        $this->assertSame([200000, 25000, 175000, 'cash', 'Home 20 Mbps', 'Budi'], [$p->harga, $p->diskon, $p->jml_bayar, $p->metode, $p->nama_paket, $p->nama_pelanggan]);
        $this->assertSame(sprintf('INV202610-%05d', $p->id), $p->no_invoice);
        $this->get('/pelanggan/belum-lunas')->assertInertia(fn ($page) => $page->has('rows', 0));
        $this->get('/pelanggan/lunas')->assertInertia(fn ($page) => $page->has('rows', 1)->where('jumlah.lunas', 1));
        $this->get('/pelanggan')->assertInertia(fn ($page) => $page->where('rows.0.periode_lunas', ['2026-10-01']));
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('kpi.lunas', 1)->where('kpi.pemasukan_bulan', 175000)->where('grafik.11.total', 175000));
    }

    public function test_percentage_discount_is_rounded_and_limited_to_100_percent(): void
    {
        $this->bayar(['diskon_tipe' => 'persentase', 'diskon_nilai' => 101])->assertStatus(422)->assertJsonValidationErrors(['diskon_nilai' => 'Diskon maksimal 100%']);
        $this->bayar(['diskon_tipe' => 'nominal', 'diskon_nilai' => 200001])->assertStatus(422)->assertJsonValidationErrors(['diskon_nilai' => 'Diskon tidak boleh melebihi harga paket']);

        $this->bayar(['diskon_tipe' => 'persentase', 'diskon_nilai' => 12.5])->assertOk();

        $this->assertSame([25000, 175000], [Pembayaran::sole()->diskon, Pembayaran::sole()->jml_bayar]);
    }

    public function test_any_month_and_year_in_range_can_be_paid_but_not_twice(): void
    {
        $this->bayar()->assertOk();
        $this->bayar(['bulan' => 3, 'tahun' => 2029])->assertOk();

        $this->bayar()->assertStatus(422)->assertJsonValidationErrors(['bulan' => 'Periode ini sudah dibayar']);
        $this->bayar(['bulan' => 13])->assertStatus(422)->assertJsonValidationErrors('bulan');
        $this->bayar(['tahun' => 2032])->assertStatus(422)->assertJsonValidationErrors('tahun');
        $this->assertSame(['2026-10-01', '2029-03-01'], Pembayaran::orderBy('periode')->pluck('periode')->all());
    }

    public function test_transfer_only_to_branch_or_global_account_and_cash_only_when_branch_accepts_it(): void
    {
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $rekeningLain = Rekening::create(['nama_rekening' => 'BRI', 'atas_nama' => 'Cimahi', 'no_rekening' => '111', 'id_lokasi' => $lain->id]);
        $rekeningPusat = Rekening::create(['nama_rekening' => 'BCA', 'atas_nama' => 'MSS', 'no_rekening' => '222']);
        $this->lokasi->update(['terima_cash' => false]);

        $this->bayar(['metode' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('metode');
        $this->bayar(['metode' => (string) $rekeningLain->id])->assertStatus(422)->assertJsonValidationErrors('metode');
        $this->bayar(['metode' => (string) $rekeningPusat->id])->assertOk();

        $this->assertSame(['transfer', $rekeningPusat->id], [Pembayaran::sole()->metode, Pembayaran::sole()->rekening_id]);
    }

    public function test_branch_user_cannot_pay_other_branch_customer_and_role_without_permission_is_forbidden(): void
    {
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $lain->id]));
        $this->bayar()->assertNotFound();

        $this->actingAs(User::factory()->peran('Teknisi')->create());
        $this->bayar()->assertForbidden();

        $this->assertDatabaseCount('pembayaran', 0);
    }

    public function test_paid_page_links_receipt_and_cancelling_returns_customer_to_unpaid(): void
    {
        $this->bayar(['diskon_tipe' => 'nominal', 'diskon_nilai' => 25000])->assertOk();
        $p = Pembayaran::sole();

        $this->get('/pelanggan/lunas')->assertInertia(fn ($page) => $page->where('rows.0.pembayaran_periode', ['id' => $p->id, 'no_invoice' => $p->no_invoice]));
        $this->get("/pembayaran/{$p->id}/kwitansi")->assertOk()->assertInertia(fn ($page) => $page->component('Pelanggan/Kwitansi')
            ->where('kwitansi.no_invoice', $p->no_invoice)->where('kwitansi.jml_bayar', 175000)->where('kwitansi.cabang.nama_lokasi', 'MSS Garut'));

        $this->deleteJson("/pembayaran/{$p->id}")->assertOk()->assertJsonPath('message', "Pembayaran {$p->no_invoice} (Budi) dibatalkan");

        $this->assertModelMissing($p);
        $this->get('/pelanggan/belum-lunas')->assertInertia(fn ($page) => $page->has('rows', 1));
    }

    public function test_receipt_and_cancel_are_limited_to_own_branch_and_permission(): void
    {
        $this->bayar()->assertOk();
        $p = Pembayaran::sole();
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);

        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $lain->id]));
        $this->get("/pembayaran/{$p->id}/kwitansi")->assertNotFound();
        $this->deleteJson("/pembayaran/{$p->id}")->assertNotFound();

        $this->actingAs(User::factory()->peran('Teknisi')->create());
        $this->deleteJson("/pembayaran/{$p->id}")->assertForbidden();

        $this->assertModelExists($p);
    }

    public function test_history_lists_payments_and_edit_keeps_recorded_price_and_invoice(): void
    {
        $this->bayar()->assertOk();
        $this->bayar(['bulan' => 12])->assertOk();
        $oktober = Pembayaran::firstWhere('periode', '2026-10-01');
        $this->pelanggan->paket->update(['harga' => 250000]);

        $this->getJson("/pelanggan/{$this->pelanggan->id}/pembayaran")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.periode', '2026-12-01')->assertJsonPath('data.1.no_invoice', $oktober->no_invoice);

        $this->putJson("/pembayaran/{$oktober->id}", ['bulan' => 12, 'tahun' => 2026, 'metode' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors(['bulan' => 'Periode ini sudah dibayar']);
        $this->putJson("/pembayaran/{$oktober->id}", ['bulan' => 11, 'tahun' => 2026, 'metode' => 'cash', 'diskon_tipe' => 'persentase', 'diskon_nilai' => 10])
            ->assertOk();

        $oktober->refresh();
        // Harga tetap harga saat dibayar (200.000), bukan harga paket terbaru
        $this->assertSame(['2026-11-01', 200000, 20000, 180000], [$oktober->periode, $oktober->harga, $oktober->diskon, $oktober->jml_bayar]);
        $this->assertStringStartsWith('INV202610-', $oktober->no_invoice);
    }

    public function test_history_and_edit_are_limited_to_own_branch_and_permission(): void
    {
        $this->bayar()->assertOk();
        $p = Pembayaran::sole();
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $ubah = ['bulan' => 10, 'tahun' => 2026, 'metode' => 'cash', 'diskon_tipe' => 'nominal', 'diskon_nilai' => 200000];

        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $lain->id]));
        $this->getJson("/pelanggan/{$this->pelanggan->id}/pembayaran")->assertNotFound();
        $this->putJson("/pembayaran/{$p->id}", $ubah)->assertNotFound();

        $this->actingAs(User::factory()->peran('Teknisi')->create());
        $this->getJson("/pelanggan/{$this->pelanggan->id}/pembayaran")->assertForbidden();
        $this->putJson("/pembayaran/{$p->id}", $ubah)->assertForbidden();

        $this->assertSame(200000, $p->fresh()->jml_bayar);
    }

    private function bayar(array $data = [])
    {
        return $this->postJson("/pelanggan/{$this->pelanggan->id}/bayar", ['bulan' => 10, 'tahun' => 2026, 'metode' => 'cash', ...$data]);
    }
}
