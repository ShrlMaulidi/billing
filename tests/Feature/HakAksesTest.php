<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HakAksesTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_default_roles_and_matrix_of_selected_role(): void
    {
        $this->actingAs(User::factory()->create());
        $sales = Role::findByName('Sales');

        $this->get("/user/hak-akses?role={$sales->id}")->assertOk()->assertInertia(fn ($page) => $page->component('User/HakAkses')
            ->has('roles', 5)->where('roles.0.nama', 'Super Admin')->where('roles.0.is_locked', true)
            ->where('selected', $sales->id)
            ->where('permissions', ['dashboard' => ['view' => true], 'pelanggan.data' => ['view' => true, 'create' => true]])
            ->where('menus.0', ['key' => 'dashboard', 'label' => 'Dashboard', 'aksi' => ['view']]));
    }

    public function test_saved_permissions_take_effect_immediately(): void
    {
        $this->actingAs(User::factory()->create());
        $teknisi = User::factory()->peran('Teknisi')->create();
        $role = Role::findByName('Teknisi');

        $this->putJson("/user/role/{$role->id}", ['permissions' => ['dashboard.view', 'kelola_data.paket.view']])
            ->assertOk()->assertJsonPath('message', 'Hak akses role Teknisi disimpan');

        $this->actingAs($teknisi->fresh())->get('/kelola-data/paket')->assertOk();
        $this->get('/pelanggan')->assertForbidden();
    }

    public function test_update_rejects_unknown_permission_locked_super_admin_and_own_role(): void
    {
        $this->actingAs(User::factory()->create());

        $this->putJson('/user/role/'.Role::findByName('Admin')->id, ['permissions' => ['dashboard.view', 'server.shutdown']])
            ->assertStatus(422)->assertJsonValidationErrors('permissions.1');
        $this->putJson('/user/role/'.Role::findByName('Super Admin')->id, ['permissions' => []])
            ->assertStatus(422)->assertJsonPath('message', 'Role Super Admin terkunci dan tidak dapat diubah');

        $admin = User::factory()->peran('Admin')->create();
        $admin->givePermissionTo('user.hak_akses.update');
        $this->actingAs($admin)->putJson('/user/role/'.Role::findByName('Admin')->id, ['permissions' => ['user.manajemen.create']])
            ->assertStatus(422)->assertJsonPath('message', 'Tidak bisa mengubah hak akses role Anda sendiri');

        $this->assertFalse(Role::findByName('Admin')->hasPermissionTo('user.manajemen.create'));
    }

    public function test_role_can_be_added_and_removed_only_when_unused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/user/role', ['nama' => 'Kasir', 'keterangan' => 'Terima pembayaran'])->assertOk()->assertJsonPath('message', 'Role Kasir ditambahkan');
        $this->postJson('/user/role', ['nama' => 'Kasir'])->assertStatus(422)->assertJsonValidationErrors(['nama' => 'Nama role sudah dipakai']);
        $kasir = Role::findByName('Kasir');
        $user = User::factory()->peran('Kasir')->create();

        $this->deleteJson("/user/role/{$kasir->id}")->assertStatus(422)->assertJsonPath('message', 'Role Kasir masih dipakai user, ganti role user tersebut dulu');
        $user->syncRoles('Admin');
        $this->deleteJson("/user/role/{$kasir->id}")->assertOk();

        $this->assertModelMissing($kasir);
    }
}
