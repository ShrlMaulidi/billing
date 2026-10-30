<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Lokasi;
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

    public function test_store_saves_branch_and_shares_it_as_branch_option(): void
    {
        $mitra = User::factory()->create(['name' => 'Rifky', 'role' => 'Mitra']);

        $this->postJson('/cabang', $this->data(['id_mitra' => $mitra->id]))->assertOk()->assertJsonPath('message', 'Cabang MSS Garut ditambahkan');

        $lokasi = Lokasi::firstWhere('kode_lokasi', 'C001');
        $this->assertSame([$mitra->id, false], [$lokasi->id_mitra, $lokasi->terima_cash]);
        $this->get('/kelola-data/cabang')->assertOk()->assertInertia(fn ($page) => $page->component('KelolaData/Cabang')
            ->has('rows', 1)->where('rows.0.nama_mitra', 'Rifky')->where('rows.0.jumlah_area', 0)
            ->where('mitraOptions', [['value' => $mitra->id, 'label' => 'Rifky']])
            ->where('cabangOptions', [['value' => $lokasi->id, 'label' => 'MSS Garut']]));
    }

    public function test_store_rejects_duplicate_code_and_non_mitra_owner(): void
    {
        Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Pusat']);
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->postJson('/cabang', $this->data(['id_mitra' => $admin->id]))->assertStatus(422)
            ->assertJsonValidationErrors(['kode_lokasi' => 'Kode lokasi sudah dipakai', 'id_mitra' => 'Mitra tidak ditemukan']);

        $this->assertDatabaseCount('lokasi', 1);
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
            'status' => 'aktif', 'terima_cash' => false, ...$override,
        ];
    }
}
