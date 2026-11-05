<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\PelangganInstansi;
use App\Models\Pembayaran;
use App\Models\Rekening;
use App\Models\SetoranMitra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SetoranMitraTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $garut;

    private Lokasi $cimahi;

    private User $mitra;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-15 10:00:00');
        $this->garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut', 'nama_mitra' => 'Mitra Garut']);
        $this->cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $this->mitra = User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]);
    }

    public function test_super_admin_records_deposit_which_increases_mitra_balance(): void
    {
        // Tanpa tarif: cash Rp1.000.000 dipegang mitra → pendapatan = cash, saldo 0. Dengan bagi hasil 40% saldo jadi −Rp400.000.
        $this->garut->pajak()->create([
            'periode' => '2026-01-01', 'ppn_persen' => 0, 'pph_persen' => 0, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 0,
            'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 40,
        ]);
        Pembayaran::create(['id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nama_pelanggan' => 'P', 'nama_paket' => 'P', 'harga' => 1000000, 'jml_bayar' => 1000000, 'metode' => 'cash', 'paid_at' => '2026-10-10 09:00:00']);
        $pusat = Rekening::create(['nama_rekening' => 'Mandiri', 'atas_nama' => 'MSS', 'no_rekening' => '999']);
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->get('/transaksi/setor-kewajiban')->assertInertia(fn ($page) => $page
            ->missing('kewajiban')
            ->loadDeferredProps(fn ($reload) => $reload->where("kewajiban.{$this->garut->id}.2026-10-01", 400000))
            ->where('rekeningOptions', [['value' => 'cash', 'label' => 'Cash (diterima di kantor pusat)'], ['value' => (string) $pusat->id, 'label' => 'Mandiri · 999 a.n. MSS']]));

        $this->post('/transaksi/setor-kewajiban', [
            'id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nominal' => 250000, 'metode' => (string) $pusat->id,
            'bukti_setor' => UploadedFile::fake()->image('setor.jpg'), 'keterangan' => 'Setoran pertama',
        ], ['Accept' => 'application/json'])->assertOk();

        $setoran = SetoranMitra::sole();
        $this->assertSame(['transfer', $pusat->id, 250000, $admin->id], [$setoran->metode, $setoran->rekening_id, $setoran->nominal, $setoran->user_id]);
        Storage::disk('local')->assertExists($setoran->bukti_setor);

        $this->actingAs($this->mitra)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('saldoMitra.sudah_setor', 250000)->where('saldoMitra.sisa_setor', 150000)->where('saldoMitra.saldo', -150000));
        $this->get('/transaksi/setor-kewajiban')->assertInertia(fn ($page) => $page
            ->has('rows', 1)->where('rows.0.rekening', 'Mandiri · 999 a.n. MSS')->where('rows.0.ada_bukti', true)
            ->loadDeferredProps(fn ($reload) => $reload->where("kewajiban.{$this->garut->id}.2026-10-01", 150000)));
        $this->get("/transaksi/setor-kewajiban/{$setoran->id}/bukti")->assertOk();
    }

    public function test_deposit_must_go_to_head_office_account_or_cash(): void
    {
        $rekeningCabang = Rekening::create(['nama_rekening' => 'BRI', 'atas_nama' => 'Mitra', 'no_rekening' => '111', 'id_lokasi' => $this->garut->id]);
        $this->actingAs(User::factory()->create());

        $this->postJson('/transaksi/setor-kewajiban', ['id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nominal' => 1000, 'metode' => (string) $rekeningCabang->id])
            ->assertJsonValidationErrors('metode');
        $this->postJson('/transaksi/setor-kewajiban', ['id_lokasi' => $this->garut->id, 'periode' => '2026-11-01', 'nominal' => 1000, 'metode' => 'cash'])
            ->assertJsonValidationErrors('periode');
        $this->postJson('/transaksi/setor-kewajiban', ['id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nominal' => 1000, 'metode' => 'cash', 'bukti_setor' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertJsonValidationErrors('bukti_setor');
        $this->assertDatabaseCount('setoran_mitra', 0);
    }

    public function test_mitra_only_sees_own_branch_and_cannot_record_or_cancel_deposits(): void
    {
        $milikGarut = SetoranMitra::create(['id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nominal' => 100000, 'metode' => 'cash']);
        SetoranMitra::create(['id_lokasi' => $this->cimahi->id, 'periode' => '2026-10-01', 'nominal' => 200000, 'metode' => 'cash']);
        $korporat = PelangganInstansi::create(['tipe' => 'korporat', 'nama' => 'PT Maju', 'alamat' => 'Garut', 'kapasitas' => '50 Mbps', 'tanggal_aktif' => '2026-01-01']);
        $korporat->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 1000000, 'ppn' => 110000, 'total' => 1110000]);
        $this->actingAs($this->mitra);

        // Pembayaran korporat & pilihan nama untuk form tambah khusus user pusat
        $this->get("/transaksi/setor-kewajiban?cabang={$this->cimahi->id}")->assertInertia(fn ($page) => $page
            ->has('rows', 1)->where('rows.0.nama', 'MSS Garut')->where('namaOptions', [])
            ->loadDeferredProps(fn ($reload) => $reload->has("kewajiban.{$this->garut->id}")->missing("kewajiban.{$this->cimahi->id}")));
        $this->postJson('/transaksi/setor-kewajiban', ['id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nominal' => 1000000, 'metode' => 'cash'])->assertForbidden();
        $this->deleteJson("/transaksi/setor-kewajiban/{$milikGarut->id}")->assertForbidden();
        $this->assertDatabaseCount('setoran_mitra', 2);
    }

    public function test_super_admin_can_cancel_wrong_deposit_and_its_photo_is_removed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/transaksi/setor-kewajiban', [
            'id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nominal' => 500000, 'metode' => 'cash', 'bukti_setor' => UploadedFile::fake()->image('setor.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $setoran = SetoranMitra::sole();

        $this->deleteJson("/transaksi/setor-kewajiban/{$setoran->id}")->assertOk();

        $this->assertDatabaseCount('setoran_mitra', 0);
        Storage::disk('local')->assertMissing($setoran->bukti_setor);
    }
}
