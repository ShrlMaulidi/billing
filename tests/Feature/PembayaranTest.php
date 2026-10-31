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
        $this->get('/pelanggan/lunas')->assertInertia(fn ($page) => $page->has('rows', 1)->where('jumlah.lunas', 1)
            ->where('rows.0.pembayaran_periode.harga', 200000)->where('rows.0.pembayaran_periode.diskon', 25000)->where('rows.0.pembayaran_periode.jml_bayar', 175000));
        $this->get('/pelanggan')->assertInertia(fn ($page) => $page->where('rows.0.periode_lunas', ['2026-10-01'])->where('rows.0.pembayaran_terakhir', '2026-10-01'));
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

        $this->get('/pelanggan/lunas')->assertInertia(fn ($page) => $page->where('rows.0.pembayaran_periode.id', $p->id)->where('rows.0.pembayaran_periode.no_invoice', $p->no_invoice));
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

        $this->get('/pelanggan')->assertInertia(fn ($page) => $page->where('rows.0.pembayaran_terakhir', '2026-12-01'));
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

    public function test_postpaid_payment_shows_billing_month_after_usage_month_and_keeps_it_when_customer_type_changes(): void
    {
        $this->pelanggan->update(['jns_tagihan' => 'pascabayar']);
        $this->bayar(['bulan' => 9])->assertOk();
        $p = Pembayaran::sole();
        $this->pelanggan->update(['jns_tagihan' => 'prabayar']);

        $this->assertSame(['2026-09-01', 'pascabayar'], [$p->periode, $p->jns_tagihan]);
        $this->getJson("/pelanggan/{$this->pelanggan->id}/pembayaran")
            ->assertJsonPath('data.0.periode', '2026-09-01')->assertJsonPath('data.0.periode_pembayaran', '2026-10-01');
        $this->get("/pembayaran/{$p->id}/kwitansi")->assertInertia(fn ($page) => $page
            ->where('kwitansi.periode', '2026-09-01')->where('kwitansi.periode_pembayaran', '2026-10-01')->where('kwitansi.jns_tagihan', 'pascabayar'));
        $this->get('/pelanggan')->assertInertia(fn ($page) => $page
            ->where('rows.0.pembayaran_terakhir', '2026-09-01')->where('rows.0.periode_pembayaran_terakhir', '2026-10-01'));

        $this->bayar()->assertOk();
        $this->assertSame('2026-10-01', Pembayaran::firstWhere('periode', '2026-10-01')->periodePembayaran());
    }

    public function test_bulk_payment_pays_each_billed_period_with_server_price_and_skips_paid_customers(): void
    {
        $sari = $this->pelangganLain('Sari', ['jns_tagihan' => 'pascabayar']);
        $tono = $this->pelangganLain('Tono', ['skema_tagihan' => 'prorate', 'tgl_registrasi' => '2026-10-20']);
        $this->get('/pelanggan/belum-lunas')->assertInertia(fn ($page) => $page->has('rows', 3)
            ->where('rows.1.nama', 'Sari')->where('rows.1.tagihan', 200000)->where('rows.1.tagihan_prorate', false)
            ->where('rows.2.nama', 'Tono')->where('rows.2.tagihan', 78000)->where('rows.2.tagihan_prorate', true));
        $this->bayar()->assertOk();

        $this->postJson('/pembayaran/massal', ['pelanggan_ids' => [$this->pelanggan->id, $sari->id, $tono->id], 'bulan' => 10, 'tahun' => 2026, 'metode' => 'cash'])
            ->assertOk()->assertJsonPath('berhasil', 2)->assertJsonPath('type', 'warning')
            ->assertJsonPath('dilewati', ['Budi (sudah lunas / tidak ada tagihan)'])
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, '2 pembayaran tersimpan, total Rp278.000'));

        // Pascabayar: tagihan Oktober = pemakaian September; prorate 12/31 hari dari 200.000 dibulatkan ke 78.000
        $this->assertSame(['2026-09-01', 200000, 'pascabayar', false], array_values($sari->pembayaran()->sole()->only(['periode', 'harga', 'jns_tagihan', 'is_prorate'])));
        $this->assertSame([78000, 78000, true], array_values($tono->pembayaran()->sole()->only(['harga', 'jml_bayar', 'is_prorate'])));
        $this->get('/pelanggan/belum-lunas')->assertInertia(fn ($page) => $page->has('rows', 0));
    }

    public function test_bulk_payment_is_rejected_for_other_branch_or_without_permission_and_skips_foreign_account(): void
    {
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $rekeningLain = Rekening::create(['nama_rekening' => 'BRI', 'atas_nama' => 'Cimahi', 'no_rekening' => '999', 'id_lokasi' => $lain->id]);
        $kirim = ['pelanggan_ids' => [$this->pelanggan->id], 'bulan' => 10, 'tahun' => 2026, 'metode' => 'cash'];

        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $lain->id]));
        $this->postJson('/pembayaran/massal', $kirim)->assertNotFound();
        $this->actingAs(User::factory()->peran('Teknisi')->create());
        $this->postJson('/pembayaran/massal', $kirim)->assertForbidden();
        $this->assertDatabaseCount('pembayaran', 0);

        $this->actingAs(User::factory()->create());
        $this->postJson('/pembayaran/massal', [...$kirim, 'metode' => (string) $rekeningLain->id])
            ->assertOk()->assertJsonPath('berhasil', 0)->assertJsonPath('dilewati', ['Budi (rekening bukan milik cabangnya)']);
        $this->postJson('/pembayaran/massal', [...$kirim, 'metode' => 'ovo'])->assertStatus(422)->assertJsonValidationErrors('metode');
        $this->assertDatabaseCount('pembayaran', 0);
    }

    public function test_invoice_shows_billed_period_amount_due_date_and_invoice_accounts(): void
    {
        $tono = $this->pelangganLain('Tono', ['jns_tagihan' => 'pascabayar', 'skema_tagihan' => 'prorate', 'tgl_registrasi' => '2026-10-20', 'tgl_jatuh_tempo' => 10]);
        Rekening::create(['nama_rekening' => 'BCA', 'atas_nama' => 'MSS', 'no_rekening' => '123', 'tampil_invoice' => true]);
        Rekening::create(['nama_rekening' => 'Mandiri', 'atas_nama' => 'MSS', 'no_rekening' => '456', 'tampil_invoice' => false]);

        // Pascabayar: tagihan November untuk pemakaian Oktober (bulan registrasi, prorate 12/31 hari)
        $this->get("/pelanggan/{$tono->id}/tagihan?bulan=11&tahun=2026")->assertOk()->assertInertia(fn ($page) => $page->component('Pelanggan/Tagihan')
            ->where('tagihan.no_tagihan', sprintf('TGH202611-%05d', $tono->id))->where('tagihan.periode_pembayaran', '2026-11-01')->where('tagihan.periode', '2026-10-01')
            ->where('tagihan.nominal', 78000)->where('tagihan.is_prorate', true)->where('tagihan.hari_prorate', '12/31')->where('tagihan.jatuh_tempo', '2026-11-10')
            ->where('tagihan.rekening', [['nama_rekening' => 'BCA', 'no_rekening' => '123', 'atas_nama' => 'MSS']])->where('tagihan.pembayaran_id', null));

        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $lain->id]));
        $this->get("/pelanggan/{$tono->id}/tagihan")->assertNotFound();
        $teknisi = User::factory()->peran('Teknisi')->create();
        $this->actingAs($teknisi)->get("/pelanggan/{$tono->id}/tagihan")->assertForbidden();
        // Cukup izin Tagihan Prorate untuk membuka invoice dari menu Tagihan Prorate
        $teknisi->givePermissionTo('pelanggan.prorate.view');
        $this->get("/pelanggan/{$tono->id}/tagihan?bulan=11&tahun=2026")->assertOk();
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

    private function pelangganLain(string $nama, array $attrs = []): Pelanggan
    {
        return Pelanggan::create([
            'nama' => $nama, 'username_pppoe' => strtolower($nama), 'id_lokasi' => $this->lokasi->id, 'id_paket' => $this->pelanggan->id_paket,
            'tgl_registrasi' => '2026-08-01', ...$attrs,
        ]);
    }

    private function bayar(array $data = [])
    {
        return $this->postJson("/pelanggan/{$this->pelanggan->id}/bayar", ['bulan' => 10, 'tahun' => 2026, 'metode' => 'cash', ...$data]);
    }
}
