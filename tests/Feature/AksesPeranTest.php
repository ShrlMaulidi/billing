<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
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
        $this->actingAs(User::factory()->peran('Admin')->create());

        $this->json($method, $url, ['nama_router' => 'Disusupkan'])->assertForbidden()->assertJsonPath('message', 'Anda tidak memiliki akses ke fitur ini');

        $this->assertDatabaseCount('routers', 0);
    }

    public function test_admin_can_run_daily_operations(): void
    {
        $this->actingAs(User::factory()->peran('Admin')->create());
        $router = Router::create(['nama_router' => 'CCR', 'mode_koneksi' => 'langsung', 'host' => '10.0.0.1', 'port_api' => 8728, 'username' => 'u', 'password' => 'p', 'profile_isolir' => 'ISOLIR']);

        $this->get('/dashboard')->assertOk();
        $this->get('/pelanggan')->assertOk();
        $this->get('/pelanggan/belum-lunas')->assertOk();
        $this->get('/kelola-data/paket')->assertOk();
        $this->get('/kelola-data/router')->assertForbidden();
        // Router tetap bisa dipakai lewat menu pelanggan, tanpa melihat kredensialnya
        $this->getJson("/pelanggan/akun-router/{$router->id}?jenis=static")->assertStatus(422);
    }

    public function test_sales_can_register_customers_but_not_delete_isolate_or_manage_routers(): void
    {
        $this->actingAs(User::factory()->peran('Sales')->create());
        $pelanggan = Pelanggan::create(['nama' => 'Budi', 'username_pppoe' => 'budi']);

        $this->get('/dashboard')->assertOk();
        $this->postJson('/pelanggan', [])->assertStatus(422);
        $this->deleteJson("/pelanggan/{$pelanggan->id}")->assertForbidden();
        $this->postJson("/pelanggan/{$pelanggan->id}/isolir")->assertForbidden();
        $this->get('/kelola-data/router')->assertForbidden();
        $this->get('/pelanggan/belum-lunas')->assertForbidden();

        $this->assertModelExists($pelanggan);
    }

    public function test_role_without_permissions_is_forbidden_everywhere(): void
    {
        Role::create(['name' => 'Tamu', 'guard_name' => 'web']);
        $this->actingAs(User::factory()->peran('Tamu')->create());

        $this->get('/dashboard')->assertForbidden();
        $this->postJson('/pelanggan', ['nama' => 'Disusupkan'])->assertForbidden();
        $this->get('/profil')->assertOk();

        $this->assertDatabaseCount('pelanggan', 0);
    }

    public function test_branch_user_only_reaches_customers_of_own_branch(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        Pelanggan::create(['nama' => 'Budi', 'username_pppoe' => 'budi', 'id_lokasi' => $garut->id]);
        $lain = Pelanggan::create(['nama' => 'Sari', 'username_pppoe' => 'sari', 'id_lokasi' => $cimahi->id]);
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $garut->id]));

        // Filter cabang di URL diabaikan: tetap terkunci ke cabang sendiri
        $this->get('/pelanggan?cabang='.$cimahi->id)->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama', 'Budi'));
        $this->putJson("/pelanggan/{$lain->id}", ['nama' => 'Diubah'])->assertNotFound();
        $this->deleteJson("/pelanggan/{$lain->id}")->assertNotFound();
        $this->postJson("/pelanggan/{$lain->id}/isolir")->assertNotFound();
        $this->postJson('/pelanggan', ['id_lokasi' => $cimahi->id])->assertStatus(422)->assertJsonValidationErrors(['id_lokasi' => 'Cabang tidak valid']);

        $this->assertSame('Sari', $lain->fresh()->nama);
    }

    public function test_branch_user_cannot_open_global_menus_even_with_permission(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $user = User::factory()->peran('Admin')->create(['id_lokasi' => $garut->id]);
        $user->givePermissionTo(['kelola_data.tunnel.view', 'user.manajemen.view']);

        $this->actingAs($user)->get('/kelola-data/tunnel')->assertForbidden();
        $this->get('/user/manajemen')->assertForbidden();
    }

    public function test_super_admin_keeps_access_to_tunnel_script(): void
    {
        config(['services.tunnel' => ['server' => '38.44.252.4', 'subnet' => '10.10.10.0/24', 'gateway' => '10.10.10.1']]);
        $this->actingAs(User::factory()->peran('Super Admin')->create());
        $tunnel = Tunnel::create(['nama_tunnel' => 'tnl', 'protokol' => 'L2TP', 'server' => 'vpn.mss.net.id', 'username' => 'garut', 'password' => 'x', 'ip_tunnel' => '10.10.10.2', 'status' => 'aktif']);

        $this->postJson("/mikrotik/tunnel/{$tunnel->id}/script")->assertOk();
    }
}
