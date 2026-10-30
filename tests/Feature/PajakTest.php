<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PajakTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $lokasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15');
        $this->lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->lokasi->pajak()->createMany(array_map(fn ($b) => [...$this->tarif(), 'periode' => sprintf('2026-%02d-01', $b)], range(1, 12)));
    }

    public function test_update_changes_only_the_chosen_month(): void
    {
        $this->actingAs(User::factory()->create());

        $this->putJson("/pajak/{$this->lokasi->id}", [...$this->tarif(['ppn_persen' => 12]), 'tahun' => 2026, 'bulan' => 3])
            ->assertOk()->assertJsonPath('message', 'Tarif MSS Garut Maret 2026 disimpan');

        $this->get('/kelola-data/pajak?tahun=2026')->assertInertia(fn ($page) => $page->component('KelolaData/Pajak')
            ->where('filters.cabang', $this->lokasi->id)->has('rows', 12)
            ->where('rows.1.ppn_persen', 11)->where('rows.2.ppn_persen', 12)->where('rows.3.ppn_persen', 11));
        $this->assertDatabaseCount('pajak_cabang', 12);
    }

    public function test_months_of_a_year_without_saved_rates_follow_the_last_saved_month(): void
    {
        $this->actingAs(User::factory()->create());
        $this->putJson("/pajak/{$this->lokasi->id}", [...$this->tarif(['ppn_persen' => 12]), 'tahun' => 2026, 'bulan' => 12])->assertOk();

        $this->get('/kelola-data/pajak?tahun=2027')->assertInertia(fn ($page) => $page
            ->where('rows.0.tersimpan', false)->where('rows.0.ppn_persen', 12)->where('rows.11.ppn_persen', 12));
        $this->get('/kelola-data/pajak?tahun=2025')->assertInertia(fn ($page) => $page->where('rows.0.ppn_persen', null));
    }

    public function test_update_rejects_percentage_above_100(): void
    {
        $this->actingAs(User::factory()->create());

        $this->putJson("/pajak/{$this->lokasi->id}", [...$this->tarif(['admin_fee_tipe' => 'persentase', 'admin_fee' => 101]), 'tahun' => 2026, 'bulan' => 3])
            ->assertStatus(422)->assertJsonValidationErrors(['admin_fee' => 'Admin fee harus antara 0 dan 100']);
    }

    public function test_branch_user_can_view_own_rates_but_cannot_change_them(): void
    {
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $this->lokasi->id]));

        $this->get('/kelola-data/pajak')->assertOk()->assertInertia(fn ($page) => $page->where('rows.0.nilai_bagi_hasil', 20));
        $this->putJson("/pajak/{$this->lokasi->id}", [...$this->tarif(['nilai_bagi_hasil' => 0]), 'tahun' => 2026, 'bulan' => 10])->assertForbidden();

        $this->assertSame(0, $this->lokasi->pajak()->where('nilai_bagi_hasil', 0)->count());
    }

    private function tarif(array $override = []): array
    {
        return [
            'ppn_persen' => 11, 'pph_persen' => 0.5, 'bhp_persen' => 0, 'uso_persen' => 0, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 2500,
            'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 20, ...$override,
        ];
    }
}
