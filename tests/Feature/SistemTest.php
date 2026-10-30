<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SistemTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_lists_database_users(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Sahrul', 'username' => 'sahrul', 'role' => 'Super Admin']));
        User::factory()->create(['name' => 'Andi', 'username' => 'andi', 'role' => 'Admin']);

        $this->get('/user/manajemen')->assertOk()->assertInertia(fn ($page) => $page->component('User/Manajemen')
            ->has('rows', 2)
            ->where('rows.0.nama', 'Andi')->where('rows.0.role', 'Admin')
            ->where('rows.1.username', 'sahrul'));
    }

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

    public function test_access_rights_page_has_no_roles_and_channel_page_returns_404(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/user/hak-akses')->assertOk()->assertInertia(fn ($page) => $page->component('User/HakAkses')->has('roles', 0)->where('selected', null));
        $this->get('/payment-gateway/1/channel')->assertNotFound();
    }
}
