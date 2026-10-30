<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_users_with_role_and_branch(): void
    {
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->actingAs(User::factory()->create(['name' => 'Sahrul']));
        User::factory()->peran('Mitra')->create(['name' => 'Andi', 'id_lokasi' => $lokasi->id]);

        $this->get('/user/manajemen')->assertOk()->assertInertia(fn ($page) => $page->component('User/Manajemen')
            ->has('rows', 2)
            ->where('rows.0.nama', 'Andi')->where('rows.0.role', 'Mitra')->where('rows.0.cabang_akses', 'MSS Garut')
            ->where('rows.1.role', 'Super Admin')->where('rows.1.cabang_akses', 'Semua Cabang'));
    }

    public function test_store_creates_branch_user_with_role_and_hashed_password(): void
    {
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->actingAs(User::factory()->create());

        $this->postJson('/user', $this->data(['id_lokasi' => (string) $lokasi->id]))->assertOk()->assertJsonPath('message', 'User Rifky ditambahkan');

        $user = User::firstWhere('username', 'rifky');
        $this->assertSame([$lokasi->id, 'aktif'], [$user->id_lokasi, $user->status]);
        $this->assertTrue($user->hasRole('Mitra'));
        $this->assertTrue(Hash::check('rahasia123', $user->password));
    }

    public function test_store_rejects_weak_password_duplicate_username_and_unknown_role(): void
    {
        $this->actingAs(User::factory()->create(['username' => 'rifky']));

        $this->postJson('/user', $this->data(['password' => 'pendek', 'role' => 'Hacker']))->assertStatus(422)->assertJsonValidationErrors([
            'username' => 'Username sudah dipakai', 'password' => 'Password minimal 8 karakter', 'role' => 'Role tidak ditemukan',
        ]);
        $this->postJson('/user', $this->data(['username' => 'baru', 'password' => 'hanyahuruf']))->assertStatus(422)
            ->assertJsonValidationErrors(['password' => 'Password harus berisi huruf dan angka']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_non_super_admin_cannot_create_or_edit_super_admin(): void
    {
        $admin = User::factory()->peran('Admin')->create();
        $admin->givePermissionTo(['user.manajemen.create', 'user.manajemen.update']);
        $superAdmin = User::factory()->create(['name' => 'Bos']);
        $this->actingAs($admin);

        $this->postJson('/user', $this->data(['role' => 'Super Admin']))->assertStatus(422)
            ->assertJsonValidationErrors(['role' => 'Hanya Super Admin yang bisa memberi role Super Admin']);
        $this->putJson("/user/{$superAdmin->id}", $this->data(['username' => $superAdmin->username, 'role' => 'Super Admin']))->assertForbidden();
        $this->putJson("/user/{$superAdmin->id}/status")->assertForbidden();

        $this->assertSame('aktif', $superAdmin->fresh()->status);
        $this->assertDatabaseMissing('users', ['username' => 'rifky']);
    }

    public function test_user_cannot_change_own_role_branch_or_status(): void
    {
        $saya = User::factory()->create(['username' => 'sahrul']);
        $this->actingAs($saya);
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);

        $this->putJson("/user/{$saya->id}", $this->data(['username' => 'sahrul', 'role' => 'Admin', 'status' => 'nonaktif', 'id_lokasi' => $lokasi->id, 'password' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['role' => 'Tidak bisa mengganti role akun sendiri']);
        $this->putJson("/user/{$saya->id}/status")->assertStatus(422);

        $this->assertTrue($saya->fresh()->isSuperAdmin());
        $this->assertSame('aktif', $saya->fresh()->status);
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $this->actingAs(User::factory()->create());
        $target = User::factory()->peran('Admin')->create();
        $tokenLama = $target->remember_token;

        $this->putJson("/user/{$target->id}/status")->assertOk()->assertJsonPath('message', "User {$target->name} dinonaktifkan");

        $target->refresh();
        $this->assertSame('nonaktif', $target->status);
        $this->assertNotSame($tokenLama, $target->remember_token);
        $this->actingAs($target)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    private function data(array $override = []): array
    {
        return ['nama' => 'Rifky', 'username' => 'rifky', 'email' => null, 'role' => 'Mitra', 'id_lokasi' => null, 'password' => 'rahasia123', 'status' => 'aktif', ...$override];
    }
}
