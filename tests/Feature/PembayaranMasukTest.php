<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\PelangganInstansi;
use App\Models\PembayaranInstansi;
use App\Models\SetoranMitra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PembayaranMasukTest extends TestCase
{
    use RefreshDatabase;

    private PelangganInstansi $korporat;

    private PelangganInstansi $dinas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $this->korporat = $this->instansi(['nama' => 'PT Maju', 'harga' => 5000000]);
        $this->dinas = $this->instansi(['tipe' => 'pemerintah', 'nama' => 'Dinas Kominfo', 'harga' => 2000000, 'status' => 'berhenti']);
        $this->actingAs(User::factory()->create());
    }

    public function test_store_calculates_ppn_and_total_on_the_server(): void
    {
        $this->postJson('/transaksi/setor-kewajiban/instansi', [
            'tipe' => 'korporat', 'pelanggan_instansi_id' => $this->korporat->id, 'nominal' => 1234567, 'ppn_persen' => 11, 'periode' => '2026-10-01',
            'ppn' => 1, 'total' => 1,
        ])->assertOk()->assertJsonPath('message', 'Pembayaran PT Maju Rp1.370.369 dicatat');

        $pembayaran = PembayaranInstansi::sole();
        $this->assertSame([1234567, 11.0, 135802, 1370369, '2026-10-01'], [$pembayaran->nominal, $pembayaran->ppn_persen, $pembayaran->ppn, $pembayaran->total, $pembayaran->periode]);
        $this->get('/transaksi/setor-kewajiban')->assertInertia(fn ($page) => $page->has('rows', 1)
            ->where('rows.0.key', "instansi-{$pembayaran->id}")->where('rows.0.tipe', 'korporat')->where('rows.0.nama', 'PT Maju')->where('rows.0.total', 1370369));
    }

    public function test_store_rejects_customer_of_another_type_and_out_of_range_values(): void
    {
        $this->postJson('/transaksi/setor-kewajiban/instansi', [
            'tipe' => 'korporat', 'pelanggan_instansi_id' => $this->dinas->id, 'nominal' => 0, 'ppn_persen' => 120, 'periode' => '2023-12-01',
        ])->assertStatus(422)->assertJsonValidationErrors([
            'pelanggan_instansi_id' => 'Nama pelanggan tidak ditemukan', 'nominal' => 'Nominal pembayaran minimal 1',
            'ppn_persen' => 'PPN harus antara 0 dan 100', 'periode' => 'Periode tidak valid',
        ]);
        $this->postJson('/transaksi/setor-kewajiban/instansi', ['tipe' => 'reseller'])->assertStatus(422)->assertJsonValidationErrors([
            'tipe' => 'Tipe pelanggan tidak valid', 'pelanggan_instansi_id' => 'Nama pelanggan wajib diisi', 'nominal' => 'Nominal pembayaran wajib diisi', 'ppn_persen' => 'PPN wajib diisi',
        ]);

        $this->assertDatabaseCount('pembayaran_instansi', 0);
    }

    public function test_period_options_go_back_two_years_and_only_institutions_can_pay_ahead(): void
    {
        $garut = Lokasi::create(['tipe' => 'reseller', 'kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);

        $this->get('/transaksi/setor-kewajiban')->assertInertia(fn ($page) => $page
            ->where('periodeSekarang', '2026-10-01')
            ->has('periodeOptions.cabang', 34)->where('periodeOptions.cabang.0', '2024-01-01')->where('periodeOptions.cabang.33', '2026-10-01')
            ->has('periodeOptions.instansi', 48)->where('periodeOptions.instansi.47', '2027-12-01'));

        $bayar = fn (string $periode) => $this->postJson('/transaksi/setor-kewajiban/instansi', [
            'tipe' => 'korporat', 'pelanggan_instansi_id' => $this->korporat->id, 'nominal' => 1000, 'ppn_persen' => 0, 'periode' => $periode,
        ]);
        $bayar('2027-12-01')->assertOk();
        $bayar('2028-01-01')->assertStatus(422)->assertJsonValidationErrors(['periode' => 'Periode tidak valid']);
        // Setoran mitra: Januari 2024 boleh, bulan depan belum
        $this->postJson('/transaksi/setor-kewajiban', ['id_lokasi' => $garut->id, 'periode' => '2024-01-01', 'nominal' => 1000, 'metode' => 'cash'])->assertOk();
        $this->postJson('/transaksi/setor-kewajiban', ['id_lokasi' => $garut->id, 'periode' => '2026-11-01', 'nominal' => 1000, 'metode' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors(['periode' => 'Periode tidak valid']);

        $this->assertDatabaseCount('pembayaran_instansi', 1);
        $this->assertDatabaseCount('setoran_mitra', 1);
    }

    public function test_list_merges_branch_deposits_and_institution_payments_with_type_filter(): void
    {
        $garut = Lokasi::create(['tipe' => 'reseller', 'kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $shafa = Lokasi::create(['tipe' => 'retail', 'kode_lokasi' => 'C002', 'nama_lokasi' => 'Shafa.net']);
        SetoranMitra::create(['id_lokasi' => $garut->id, 'periode' => '2026-10-01', 'nominal' => 100000, 'metode' => 'cash']);
        SetoranMitra::create(['id_lokasi' => $shafa->id, 'periode' => '2026-10-01', 'nominal' => 200000, 'metode' => 'cash']);
        $this->korporat->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 1000000, 'ppn' => 110000, 'total' => 1110000]);
        $this->dinas->pembayaran()->create(['periode' => '2026-09-01', 'nominal' => 500000, 'ppn' => 0, 'total' => 500000]);

        $this->get('/transaksi/setor-kewajiban')->assertInertia(fn ($page) => $page->has('rows', 3));
        $this->get('/transaksi/setor-kewajiban?semua=1')->assertInertia(fn ($page) => $page->has('rows', 4));
        $this->get('/transaksi/setor-kewajiban?tipe=retail')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama', 'Shafa.net')->where('rows.0.total', 200000));
        $this->get('/transaksi/setor-kewajiban?tipe=pemerintah&semua=1')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama', 'Dinas Kominfo'));
        // Pembayaran instansi tidak terikat cabang
        $this->get("/transaksi/setor-kewajiban?cabang={$garut->id}")->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama', 'MSS Garut'));
        $this->get('/transaksi/setor-kewajiban')->assertInertia(fn ($page) => $page
            ->where('namaOptions.reseller', [['value' => $garut->id, 'label' => 'MSS Garut']])
            ->where('namaOptions.retail', [['value' => $shafa->id, 'label' => 'Shafa.net']])
            ->where('namaOptions.korporat', [['value' => $this->korporat->id, 'label' => 'PT Maju', 'harga' => 5000000]])
            ->where('namaOptions.pemerintah', [['value' => $this->dinas->id, 'label' => 'Dinas Kominfo (Berhenti)', 'harga' => 2000000]]));
    }

    public function test_head_office_can_cancel_institution_payment(): void
    {
        $pembayaran = $this->korporat->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 1000000, 'ppn' => 110000, 'total' => 1110000]);

        $this->deleteJson("/transaksi/setor-kewajiban/instansi/{$pembayaran->id}")->assertOk()->assertJsonPath('message', 'Pembayaran PT Maju Rp1.110.000 dibatalkan');

        $this->assertModelMissing($pembayaran);
    }

    public function test_branch_user_cannot_record_or_cancel_institution_payments(): void
    {
        $pembayaran = $this->korporat->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 1000000, 'ppn' => 110000, 'total' => 1110000]);
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $garut->id]));

        $this->get('/transaksi/setor-kewajiban')->assertInertia(fn ($page) => $page->has('rows', 0));
        $this->postJson('/transaksi/setor-kewajiban/instansi', [
            'tipe' => 'korporat', 'pelanggan_instansi_id' => $this->korporat->id, 'nominal' => 1000, 'ppn_persen' => 0, 'periode' => '2026-10-01',
        ])->assertForbidden();
        $this->deleteJson("/transaksi/setor-kewajiban/instansi/{$pembayaran->id}")->assertForbidden();

        $this->assertDatabaseCount('pembayaran_instansi', 1);
    }

    private function instansi(array $override = []): PelangganInstansi
    {
        return PelangganInstansi::create([
            'tipe' => 'korporat', 'nama' => 'PT Maju', 'alamat' => 'Garut', 'kapasitas' => '100 Mbps', 'harga' => 5000000,
            'tanggal_aktif' => '2026-01-01', 'jns_tagihan' => 'prabayar', 'status' => 'berlangganan', ...$override,
        ]);
    }
}
