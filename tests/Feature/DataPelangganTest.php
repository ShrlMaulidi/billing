<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\PelangganInstansi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DataPelangganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    public function test_index_lists_branches_and_institutions_and_filters_by_type(): void
    {
        Lokasi::create(['tipe' => 'reseller', 'kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        Lokasi::create(['tipe' => 'retail', 'kode_lokasi' => 'C002', 'nama_lokasi' => 'Shafa.net']);
        PelangganInstansi::create($this->data(['nama' => 'PT Maju', 'file_ktp' => 'dokumen-pelanggan/ktp.jpg']));
        PelangganInstansi::create($this->data(['tipe' => 'pemerintah', 'nama' => 'Dinas Kominfo']));

        $this->get('/data-pelanggan')->assertOk()->assertInertia(fn ($page) => $page->component('DataPelanggan/Index')
            ->where('filters.tipe', null)->has('rows', 4)
            ->where('rows.0.key', 'lokasi-1')->where('rows.0.nama', 'MSS Garut')
            ->where('rows.3.nama', 'PT Maju')->where('rows.3.ada_ktp', true)->where('rows.3.ada_npwp', false)->missing('rows.3.file_ktp'));
        $this->get('/data-pelanggan?tipe=retail')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama', 'Shafa.net'));
        $this->get('/data-pelanggan?tipe=pemerintah')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama', 'Dinas Kominfo'));
        $this->get('/data-pelanggan?tipe=lainnya')->assertInertia(fn ($page) => $page->where('filters.tipe', null)->has('rows', 4));
    }

    public function test_store_corporate_customer_saves_documents_privately_and_normalizes_npwp(): void
    {
        $this->post('/data-pelanggan/instansi', $this->data([
            'npwp' => '01.234.567.8-901.000',
            'file_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            'file_npwp' => UploadedFile::fake()->create('npwp.pdf', 100, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('message', 'Pelanggan PT Maju Jaya ditambahkan');

        $pelanggan = PelangganInstansi::sole();
        $this->assertSame(['korporat', '012345678901000', 'Metro-01', '3205010101010001', 15000000, '2026-11-01'], [
            $pelanggan->tipe, $pelanggan->npwp, $pelanggan->metro, $pelanggan->nik, $pelanggan->harga, $pelanggan->tanggal_aktif->toDateString(),
        ]);
        Storage::disk('local')->assertExists([$pelanggan->file_ktp, $pelanggan->file_npwp]);
        $this->get("/data-pelanggan/instansi/{$pelanggan->id}/dokumen/ktp")->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_store_government_customer_drops_corporate_only_fields(): void
    {
        $this->post('/data-pelanggan/instansi', $this->data([
            'tipe' => 'pemerintah', 'nama' => 'RSUD Garut',
            'file_ktp' => UploadedFile::fake()->image('ktp.jpg'), 'file_npwp' => UploadedFile::fake()->image('npwp.jpg'),
        ]), ['Accept' => 'application/json'])->assertOk();

        $pelanggan = PelangganInstansi::sole();
        $this->assertSame(['pemerintah', null, null, null], [$pelanggan->tipe, $pelanggan->metro, $pelanggan->nik, $pelanggan->file_ktp]);
        Storage::disk('local')->assertExists($pelanggan->file_npwp);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_store_requires_main_fields(): void
    {
        $this->postJson('/data-pelanggan/instansi', [])->assertStatus(422)->assertJsonValidationErrors([
            'tipe' => 'Tipe klien wajib diisi', 'nama' => 'Nama wajib diisi', 'alamat' => 'Alamat wajib diisi', 'kapasitas' => 'Kapasitas wajib diisi',
            'harga' => 'Harga wajib diisi', 'tanggal_aktif' => 'Tanggal aktif wajib diisi', 'jns_tagihan' => 'Jenis tagihan wajib diisi', 'status' => 'Status wajib diisi',
        ]);

        $this->assertDatabaseCount('pelanggan_instansi', 0);
    }

    public function test_store_rejects_invalid_identity_numbers_and_document_types(): void
    {
        $this->post('/data-pelanggan/instansi', $this->data([
            'nik' => '12345', 'npwp' => '1234', 'file_ktp' => UploadedFile::fake()->create('ktp.svg', 10, 'image/svg+xml'),
            'file_npwp' => UploadedFile::fake()->create('npwp.pdf', 3000, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors([
            'nik' => 'NIK harus 16 digit angka', 'npwp' => 'NPWP harus 15 atau 16 digit angka',
            'file_ktp' => 'File KTP harus berformat JPG, PNG, WEBP, atau PDF', 'file_npwp' => 'Ukuran file NPWP maksimal 2 MB',
        ]);

        $this->assertDatabaseCount('pelanggan_instansi', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_update_replaces_document_keeps_the_other_and_cannot_change_type(): void
    {
        Storage::disk('local')->put('dokumen-pelanggan/ktp-lama.jpg', 'ktp');
        Storage::disk('local')->put('dokumen-pelanggan/npwp-lama.jpg', 'npwp');
        $pelanggan = PelangganInstansi::create($this->data(['file_ktp' => 'dokumen-pelanggan/ktp-lama.jpg', 'file_npwp' => 'dokumen-pelanggan/npwp-lama.jpg']));

        $this->post("/data-pelanggan/instansi/{$pelanggan->id}", [
            ...$this->data(['tipe' => 'pemerintah', 'nama' => 'PT Maju Sejahtera', 'status' => 'berhenti', 'tanggal_berhenti' => '2026-12-01']),
            '_method' => 'PUT', 'file_npwp' => UploadedFile::fake()->image('npwp-baru.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('message', 'Pelanggan PT Maju Sejahtera diperbarui');

        $pelanggan->refresh();
        $this->assertSame(['korporat', 'PT Maju Sejahtera', 'berhenti', 'dokumen-pelanggan/ktp-lama.jpg'], [$pelanggan->tipe, $pelanggan->nama, $pelanggan->status, $pelanggan->file_ktp]);
        Storage::disk('local')->assertMissing('dokumen-pelanggan/npwp-lama.jpg');
        Storage::disk('local')->assertExists($pelanggan->file_npwp);
    }

    public function test_stop_date_is_required_when_stopping_and_cleared_when_resubscribing(): void
    {
        $pelanggan = PelangganInstansi::create($this->data());
        $ubah = fn (array $data) => $this->putJson("/data-pelanggan/instansi/{$pelanggan->id}", $this->data($data));

        $ubah(['status' => 'berhenti'])->assertStatus(422)->assertJsonValidationErrors(['tanggal_berhenti' => 'Tanggal berhenti wajib diisi']);
        $ubah(['status' => 'berhenti', 'tanggal_berhenti' => '2026-10-31'])->assertStatus(422)
            ->assertJsonValidationErrors(['tanggal_berhenti' => 'Tanggal berhenti tidak boleh sebelum tanggal aktif']);
        $ubah(['status' => 'berhenti', 'tanggal_berhenti' => '2026-12-15'])->assertOk();
        $this->assertSame('2026-12-15', $pelanggan->fresh()->tanggal_berhenti->toDateString());

        $ubah(['status' => 'berlangganan', 'tanggal_berhenti' => '2026-12-15'])->assertOk();
        $this->assertNull($pelanggan->fresh()->tanggal_berhenti);
    }

    public function test_customer_can_be_assigned_to_an_active_sales_user(): void
    {
        $sales = User::factory()->peran('Sales')->create(['name' => 'Rina Sales']);
        $nonaktif = User::factory()->peran('Sales')->create(['name' => 'Dodi Lama', 'status' => 'nonaktif']);
        $teknisi = User::factory()->peran('Teknisi')->create();

        $this->get('/data-pelanggan')->assertInertia(fn ($page) => $page->where('salesOptions', [['value' => $sales->id, 'label' => 'Rina Sales']]));
        $this->postJson('/data-pelanggan/instansi', $this->data(['sales_id' => $teknisi->id]))->assertStatus(422)
            ->assertJsonValidationErrors(['sales_id' => 'Sales tidak valid']);
        $this->postJson('/data-pelanggan/instansi', $this->data(['sales_id' => $nonaktif->id]))->assertStatus(422);

        $this->postJson('/data-pelanggan/instansi', $this->data(['sales_id' => $sales->id]))->assertOk();
        $pelanggan = PelangganInstansi::sole();
        $this->assertSame($sales->id, $pelanggan->sales_id);
        $this->get('/data-pelanggan?tipe=korporat')->assertInertia(fn ($page) => $page->where('rows.0.nama_sales', 'Rina Sales'));

        // Sales yang sudah nonaktif tetap tersimpan saat data lain diubah
        $pelanggan->update(['sales_id' => $nonaktif->id]);
        $this->putJson("/data-pelanggan/instansi/{$pelanggan->id}", $this->data(['nama' => 'PT Maju Abadi', 'sales_id' => $nonaktif->id]))->assertOk();
        $this->assertSame([$nonaktif->id, 'PT Maju Abadi'], [$pelanggan->fresh()->sales_id, $pelanggan->fresh()->nama]);
        $this->putJson("/data-pelanggan/instansi/{$pelanggan->id}", $this->data(['sales_id' => '']))->assertOk();
        $this->assertNull($pelanggan->fresh()->sales_id);
    }

    public function test_destroy_removes_customer_and_documents(): void
    {
        Storage::disk('local')->put('dokumen-pelanggan/ktp.jpg', 'ktp');
        $pelanggan = PelangganInstansi::create($this->data(['file_ktp' => 'dokumen-pelanggan/ktp.jpg']));

        $this->deleteJson("/data-pelanggan/instansi/{$pelanggan->id}")->assertOk()->assertJsonPath('message', 'Pelanggan PT Maju Jaya dihapus');

        $this->assertModelMissing($pelanggan);
        Storage::disk('local')->assertMissing('dokumen-pelanggan/ktp.jpg');
    }

    public function test_destroy_is_blocked_once_the_customer_has_payments(): void
    {
        $pelanggan = PelangganInstansi::create($this->data());
        $pelanggan->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 1000000, 'ppn' => 110000, 'total' => 1110000]);

        $this->deleteJson("/data-pelanggan/instansi/{$pelanggan->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Pelanggan PT Maju Jaya sudah punya pembayaran masuk, ubah statusnya menjadi Berhenti');

        $this->assertModelExists($pelanggan);
    }

    public function test_document_without_upload_is_not_found(): void
    {
        $pelanggan = PelangganInstansi::create($this->data());

        $this->get("/data-pelanggan/instansi/{$pelanggan->id}/dokumen/npwp")->assertNotFound();
        $this->get("/data-pelanggan/instansi/{$pelanggan->id}/dokumen/lainnya")->assertNotFound();
    }

    #[TestWith(['GET', '/data-pelanggan'])]
    #[TestWith(['POST', '/data-pelanggan/instansi'])]
    #[TestWith(['GET', '/data-pelanggan/instansi/{id}/dokumen/ktp'])]
    #[TestWith(['DELETE', '/data-pelanggan/instansi/{id}'])]
    public function test_branch_user_cannot_access_customer_data_even_with_permission(string $method, string $url): void
    {
        Storage::disk('local')->put('dokumen-pelanggan/ktp.jpg', 'ktp');
        $pelanggan = PelangganInstansi::create($this->data(['file_ktp' => 'dokumen-pelanggan/ktp.jpg']));
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        // Admin bawaan memiliki permission data_pelanggan.*; user cabang tetap ditolak
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $garut->id]));

        $this->json($method, str_replace('{id}', $pelanggan->id, $url), $this->data(['nama' => 'Disusupkan']))->assertForbidden();

        $this->assertModelExists($pelanggan);
        $this->assertDatabaseCount('pelanggan_instansi', 1);
    }

    public function test_role_without_permission_cannot_add_customers_or_branches(): void
    {
        $this->actingAs(User::factory()->peran('Teknisi')->create());

        $this->get('/data-pelanggan')->assertForbidden();
        $this->postJson('/data-pelanggan/instansi', $this->data())->assertForbidden();
        $this->postJson('/cabang', ['tipe' => 'reseller', 'kode_lokasi' => 'C001'])->assertForbidden();

        $this->assertDatabaseCount('pelanggan_instansi', 0);
        $this->assertDatabaseCount('lokasi', 0);
    }

    private function data(array $override = []): array
    {
        return [
            'tipe' => 'korporat', 'nama' => 'PT Maju Jaya', 'alamat' => 'Jl. Ahmad Yani 1, Garut', 'kapasitas' => '100 Mbps', 'harga' => 15000000,
            'metro' => 'Metro-01', 'nik' => '3205010101010001', 'tanggal_aktif' => '2026-11-01', 'jns_tagihan' => 'pascabayar', 'status' => 'berlangganan',
            ...$override,
        ];
    }
}
