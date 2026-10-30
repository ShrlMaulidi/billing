<?php

namespace Tests\Feature;

use App\Models\Pelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class KelolaDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_member_cards_list_only_active_database_customers(): void
    {
        Pelanggan::create(['nama' => 'Budi', 'username_pppoe' => 'budi']);
        Pelanggan::create(['nama' => 'Lama', 'username_pppoe' => 'lama', 'status' => 'berhenti']);

        $this->get('/kelola-data/kartu-member')->assertOk()->assertInertia(fn ($page) => $page->component('KelolaData/KartuMember')
            ->has('rows', 1)->where('rows.0.nama', 'Budi')->where('rows.0.paket', '-'));
    }

    #[TestWith(['/kelola-data/cabang', 'KelolaData/Cabang'])]
    #[TestWith(['/kelola-data/area', 'KelolaData/Area'])]
    #[TestWith(['/kelola-data/pajak', 'KelolaData/Pajak'])]
    #[TestWith(['/kelola-data/rekening', 'KelolaData/Rekening'])]
    #[TestWith(['/kelola-data/notifikasi', 'KelolaData/Notifikasi'])]
    #[TestWith(['/kelola-data/pph', 'KelolaData/Pph'])]
    public function test_menu_without_table_renders_no_rows(string $url, string $component): void
    {
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component)->has('rows', 0)->where('cabangOptions', []));
    }
}
