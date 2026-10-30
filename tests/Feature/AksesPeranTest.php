<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AksesPeranTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['GET', '/kelola-data/router'])]
    #[TestWith(['GET', '/kelola-data/tunnel'])]
    #[TestWith(['GET', '/kelola-data/template-isolir'])]
    #[TestWith(['GET', '/user/manajemen'])]
    #[TestWith(['POST', '/mikrotik/router'])]
    #[TestWith(['POST', '/mikrotik/tunnel'])]
    #[TestWith(['POST', '/isolir/script'])]
    #[TestWith(['PUT', '/isolir/pengaturan'])]
    public function test_admin_is_forbidden_from_router_tunnel_script_and_user_management(string $method, string $url): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Admin']));

        $this->json($method, $url, ['nama_router' => 'Disusupkan'])->assertForbidden()->assertJsonPath('message', 'Anda tidak memiliki akses ke fitur ini');

        $this->assertDatabaseCount('routers', 0);
    }

    public function test_admin_can_run_daily_operations(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Admin']));
        $router = Router::create(['nama_router' => 'CCR', 'mode_koneksi' => 'langsung', 'host' => '10.0.0.1', 'port_api' => 8728, 'username' => 'u', 'password' => 'p', 'profile_isolir' => 'ISOLIR']);

        $this->get('/dashboard')->assertOk();
        $this->get('/pelanggan')->assertOk();
        $this->get('/kelola-data/paket')->assertOk();
        $this->get('/kelola-data/router')->assertForbidden();
        // Router tetap bisa dipakai lewat menu pelanggan, tanpa melihat kredensialnya
        $this->getJson("/pelanggan/akun-router/{$router->id}?jenis=static")->assertStatus(422);
    }

    #[TestWith(['Sales'])]
    #[TestWith(['Mitra'])]
    #[TestWith(['Teknisi'])]
    public function test_roles_without_app_access_are_forbidden_everywhere(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get('/dashboard')->assertForbidden();
        $this->postJson('/pelanggan', ['nama' => 'Disusupkan'])->assertForbidden();
        $this->post('/logout')->assertRedirect('/login');

        $this->assertDatabaseCount('pelanggan', 0);
    }

    public function test_super_admin_keeps_access_to_tunnel_script(): void
    {
        config(['services.tunnel' => ['server' => '38.44.252.4', 'subnet' => '10.10.10.0/24', 'gateway' => '10.10.10.1']]);
        $this->actingAs(User::factory()->create(['role' => 'Super Admin']));
        $tunnel = Tunnel::create(['nama_tunnel' => 'tnl', 'protokol' => 'L2TP', 'server' => 'vpn.mss.net.id', 'username' => 'garut', 'password' => 'x', 'ip_tunnel' => '10.10.10.2', 'status' => 'aktif']);

        $this->postJson("/mikrotik/tunnel/{$tunnel->id}/script")->assertOk();
    }
}
