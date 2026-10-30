<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Lokasi;
use App\Models\Pelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_saves_area_and_index_counts_active_customers(): void
    {
        $this->actingAs(User::factory()->create());
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);

        $this->postJson('/area', ['id_lokasi' => $lokasi->id, 'nama_area' => 'Garut Kota', 'status' => 'aktif'])
            ->assertOk()->assertJsonPath('message', 'Area Garut Kota ditambahkan');

        $area = Area::firstWhere('nama_area', 'Garut Kota');
        Pelanggan::create(['nama' => 'Budi', 'username_pppoe' => 'budi', 'id_lokasi' => $lokasi->id, 'id_area' => $area->id]);
        Pelanggan::create(['nama' => 'Lama', 'username_pppoe' => 'lama', 'id_lokasi' => $lokasi->id, 'id_area' => $area->id, 'status' => 'berhenti']);
        $this->get('/kelola-data/area')->assertOk()->assertInertia(fn ($page) => $page->component('KelolaData/Area')
            ->has('rows', 1)->where('rows.0.cabang', 'MSS Garut')->where('rows.0.jumlah_pelanggan', 1));
    }

    public function test_store_requires_existing_branch(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/area', ['id_lokasi' => 99, 'nama_area' => 'Garut Kota', 'status' => 'aktif'])
            ->assertStatus(422)->assertJsonValidationErrors(['id_lokasi' => 'Cabang tidak ditemukan']);

        $this->assertDatabaseCount('area', 0);
    }

    public function test_destroy_keeps_customers_without_area(): void
    {
        $this->actingAs(User::factory()->create());
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $area = Area::create(['nama_area' => 'Garut Kota', 'id_lokasi' => $lokasi->id]);
        $pelanggan = Pelanggan::create(['nama' => 'Budi', 'username_pppoe' => 'budi', 'id_area' => $area->id]);

        $this->deleteJson("/area/{$area->id}")->assertOk()->assertJsonPath('message', 'Area Garut Kota dihapus');

        $this->assertModelMissing($area);
        $this->assertNull($pelanggan->fresh()->id_area);
    }
}
