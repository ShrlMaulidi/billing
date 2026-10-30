<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Lokasi;
use App\Models\PajakCabang;
use App\Models\Pelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CabangTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_store_saves_branch_with_free_text_owner_and_shares_it_as_branch_option(): void
    {
        $this->postJson('/cabang', $this->data(['nama_mitra' => 'Rifky Hidayat']))->assertOk()->assertJsonPath('message', 'Cabang MSS Garut ditambahkan');

        $lokasi = Lokasi::firstWhere('kode_lokasi', 'C001');
        $this->assertSame(['Rifky Hidayat', false], [$lokasi->nama_mitra, $lokasi->terima_cash]);
        $this->get('/kelola-data/cabang')->assertOk()->assertInertia(fn ($page) => $page->component('KelolaData/Cabang')
            ->has('rows', 1)->where('rows.0.nama_mitra', 'Rifky Hidayat')->where('rows.0.jumlah_area', 0)
            ->where('cabangOptions', [['value' => $lokasi->id, 'label' => 'MSS Garut']]));
    }

    public function test_store_applies_initial_tax_to_every_month_of_the_current_year(): void
    {
        $this->travelTo('2026-10-15');

        $this->postJson('/cabang', $this->data(['ppn_persen' => 11, 'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 30]))->assertOk();

        $lokasi = Lokasi::firstWhere('kode_lokasi', 'C001');
        $this->assertSame(
            array_map(fn ($b) => sprintf('2026-%02d-01', $b), range(1, 12)),
            $lokasi->pajak()->orderBy('periode')->pluck('periode')->all(),
        );
        $this->assertSame(12, PajakCabang::where(['ppn_persen' => 11, 'nilai_bagi_hasil' => 30])->count());
        $this->get('/kelola-data/cabang')->assertInertia(fn ($page) => $page->where('rows.0.ppn_persen', 11)->where('rows.0.nilai_bagi_hasil', 30));
    }

    public function test_store_rejects_percentage_profit_share_above_100_and_creates_nothing(): void
    {
        $this->postJson('/cabang', $this->data(['metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 150]))
            ->assertStatus(422)->assertJsonValidationErrors(['nilai_bagi_hasil' => 'Nilai bagi hasil harus antara 0 dan 100']);

        $this->assertDatabaseCount('lokasi', 0);
        $this->assertDatabaseCount('pajak_cabang', 0);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Pusat']);

        $this->postJson('/cabang', $this->data())->assertStatus(422)->assertJsonValidationErrors(['kode_lokasi' => 'Kode lokasi sudah dipakai']);

        $this->assertDatabaseCount('lokasi', 1);
    }

    public function test_destroy_is_blocked_while_user_accounts_are_assigned_to_the_branch(): void
    {
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        User::factory()->peran('Mitra')->create(['id_lokasi' => $lokasi->id]);

        $this->deleteJson("/cabang/{$lokasi->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Cabang MSS Garut masih dipakai akun user, pindahkan dulu ke cabang lain');

        $this->assertModelExists($lokasi);
    }

    public function test_branch_user_only_sees_and_edits_own_branch_and_cannot_create_branches(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $garut->id]));

        $this->get('/kelola-data/cabang?cabang='.$cimahi->id)->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama_lokasi', 'MSS Garut')
            ->where('cabangOptions', [['value' => $garut->id, 'label' => 'MSS Garut']]));
        $this->putJson("/cabang/{$cimahi->id}", $this->data(['kode_lokasi' => 'C002', 'nama_lokasi' => 'Diubah']))->assertNotFound();
        $this->postJson('/cabang', $this->data(['kode_lokasi' => 'C003']))->assertForbidden();

        $this->assertSame('MSS Cimahi', $cimahi->fresh()->nama_lokasi);
        $this->assertDatabaseCount('lokasi', 2);
    }

    public function test_update_keeps_own_code(): void
    {
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);

        $this->putJson("/cabang/{$lokasi->id}", $this->data(['nama_lokasi' => 'MSS Garut Kota']))->assertOk()->assertJsonPath('message', 'Cabang MSS Garut Kota diperbarui');

        $this->assertSame('MSS Garut Kota', $lokasi->fresh()->nama_lokasi);
    }

    public function test_destroy_is_blocked_while_customers_use_the_branch_and_removes_its_areas(): void
    {
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $area = Area::create(['nama_area' => 'Garut Kota', 'id_lokasi' => $lokasi->id]);
        $pelanggan = Pelanggan::create(['nama' => 'Budi', 'username_pppoe' => 'budi', 'id_lokasi' => $lokasi->id]);

        $this->deleteJson("/cabang/{$lokasi->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Cabang MSS Garut masih dipakai pelanggan, pindahkan dulu ke cabang lain');
        $this->assertModelExists($lokasi);

        $pelanggan->delete();
        $this->deleteJson("/cabang/{$lokasi->id}")->assertOk();
        $this->assertModelMissing($lokasi);
        $this->assertModelMissing($area);
    }

    private function data(array $override = []): array
    {
        return [
            'kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut', 'alamat_lokasi' => 'Jl. Ciledug 10', 'tlp_lokasi' => '0262123456',
            'status' => 'aktif', 'terima_cash' => false,
            'ppn_persen' => 11, 'pph_persen' => 0.5, 'bhp_persen' => 0, 'uso_persen' => 0, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 2500,
            'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 20, ...$override,
        ];
    }
}
