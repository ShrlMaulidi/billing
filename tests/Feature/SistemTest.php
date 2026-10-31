<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SistemTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_shows_logged_in_user(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Sahrul', 'username' => 'sahrul', 'email' => 'sahrul1@gmail.com']));

        $this->get('/profil')->assertOk()->assertInertia(fn ($page) => $page->component('User/Profil')
            ->where('user.nama', 'Sahrul')->where('user.username', 'sahrul')->where('user.email', 'sahrul1@gmail.com')->where('user.last_login_at', null));
    }

    #[TestWith(['/laporan', 'Laporan/Index'])]
    #[TestWith(['/payment-gateway', 'PaymentGateway/Index'])]
    #[TestWith(['/logs', 'Logs/Index'])]
    public function test_menu_without_table_renders_no_rows(string $url, string $component): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component)->has('rows', 0));
    }

    public function test_payment_gateway_page_follows_branch_admin_full(): void
    {
        $biasa = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $full = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi', 'admin_full' => true]);

        $this->actingAs(User::factory()->create());
        $this->get('/payment-gateway')->assertInertia(fn ($page) => $page->where('cabangUser', null)
            ->where('pemilikOptions', [
                ['value' => 'perusahaan', 'label' => 'Perusahaan (dipakai cabang non Admin Full)'],
                ['value' => $full->id, 'label' => 'MSS Cimahi (Admin Full)'],
            ]));

        foreach ([[$biasa, false], [$full, true]] as [$lokasi, $adminFull]) {
            $mitra = User::factory()->peran('Mitra')->create(['id_lokasi' => $lokasi->id]);
            $mitra->givePermissionTo('payment_gateway.view');
            $this->actingAs($mitra)->get('/payment-gateway')->assertInertia(fn ($page) => $page
                ->where('cabangUser', ['id' => $lokasi->id, 'nama_lokasi' => $lokasi->nama_lokasi, 'admin_full' => $adminFull])->where('pemilikOptions', []));
        }
    }

    public function test_payment_gateway_channel_page_returns_404(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/payment-gateway/1/channel')->assertNotFound();
    }

    public function test_profile_is_open_to_any_logged_in_user(): void
    {
        $this->actingAs(User::factory()->peran('Teknisi')->create(['name' => 'Bayu']));

        $this->get('/profil')->assertOk()->assertInertia(fn ($page) => $page->where('user.nama', 'Bayu')->where('user.role', 'Teknisi')->where('user.cabang_akses', 'Semua Cabang'));
    }
}
