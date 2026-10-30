<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Pembayaran;
use App\Models\Rekening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RekeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_saves_global_account_and_branch_filter_still_lists_it(): void
    {
        $this->actingAs(User::factory()->create());
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);

        $this->postJson('/rekening', ['nama_rekening' => 'BCA', 'atas_nama' => 'MSS', 'no_rekening' => '1234567890', 'tampil_invoice' => true])
            ->assertOk()->assertJsonPath('message', 'Rekening BCA ditambahkan');

        $this->get("/kelola-data/rekening?cabang={$lokasi->id}")->assertInertia(fn ($page) => $page->component('KelolaData/Rekening')
            ->has('rows', 1)->where('rows.0.cabang', 'Semua cabang'));
    }

    public function test_branch_user_cannot_change_global_or_other_branch_accounts(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $pusat = Rekening::create(['nama_rekening' => 'BCA', 'atas_nama' => 'MSS', 'no_rekening' => '111']);
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $garut->id]));

        $this->putJson("/rekening/{$pusat->id}", ['nama_rekening' => 'Diubah', 'atas_nama' => 'X', 'no_rekening' => '999'])->assertNotFound();
        $this->postJson('/rekening', ['nama_rekening' => 'BRI', 'atas_nama' => 'X', 'no_rekening' => '222'])->assertStatus(422)->assertJsonValidationErrors('id_lokasi');

        $this->assertSame('BCA', $pusat->fresh()->nama_rekening);
    }

    public function test_destroy_is_blocked_once_used_by_a_payment(): void
    {
        $this->actingAs(User::factory()->create());
        $rekening = Rekening::create(['nama_rekening' => 'BCA', 'atas_nama' => 'MSS', 'no_rekening' => '111']);
        Pembayaran::create([
            'periode' => '2026-10-01', 'nama_pelanggan' => 'Budi', 'nama_paket' => 'Home', 'harga' => 1000, 'jml_bayar' => 1000,
            'metode' => 'transfer', 'rekening_id' => $rekening->id, 'paid_at' => now(),
        ]);

        $this->deleteJson("/rekening/{$rekening->id}")->assertStatus(422);

        $this->assertModelExists($rekening);
    }
}
