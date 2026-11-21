<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\User;
use App\Services\AkunPelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AkunPelangganTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $lokasi;

    private Router $router;

    private Paket $paket;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->router = Router::create([
            'nama_router' => 'CCR Pusat', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.10', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR',
        ]);
        $this->paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'static', 'profile_mikrotik' => '20Mbps']);
    }

    public function test_adding_customer_creates_customer_account_with_phone_as_username_and_password(): void
    {
        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data(['tlp' => '+62 812-3456-7890']))->assertOk();

        $user = User::firstWhere('username', '081234567890');
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(AkunPelanggan::ROLE));
        $this->assertTrue(Hash::check('081234567890', $user->password));
        $this->assertSame(['Budi Santoso', $this->lokasi->id, 'aktif', true], [$user->name, $user->id_lokasi, $user->status, $user->wajib_ganti_password]);
        $this->assertSame($user->id, Pelanggan::firstWhere('nama', 'Budi Santoso')->user_id);
    }

    public function test_new_customer_logs_in_with_phone_in_any_format_and_must_create_a_new_password_first(): void
    {
        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data())->assertOk();
        auth()->logout();

        $this->post('/login', ['username' => '+62 812-3456-7890', 'password' => '081234567890'])->assertRedirect('/akun-saya/password-baru');

        $this->get('/akun-saya/password-baru')->assertOk()->assertInertia(fn ($page) => $page->component('Portal/PasswordBaru'));
        $this->get('/akun-saya')->assertRedirect('/akun-saya/password-baru');
        $this->getJson('/akun-saya')->assertForbidden()->assertJsonPath('message', 'Buat password baru terlebih dahulu');
        $this->get('/cek-tagihan')->assertOk();
    }

    public function test_new_password_must_not_be_the_phone_number_and_unlocks_own_page(): void
    {
        $akun = $this->akunPelanggan();

        $this->actingAs($akun)->putJson('/akun-saya/password', ['password' => '081234567890', 'password_confirmation' => '081234567890'])
            ->assertStatus(422)->assertJsonValidationErrors(['password' => 'Password harus berisi huruf dan angka']);
        $this->putJson('/akun-saya/password', ['password' => 'rahasiaBaru1', 'password_confirmation' => 'rahasiaBaru1'])->assertOk();

        $akun->refresh();
        $this->assertFalse($akun->wajib_ganti_password);
        $this->assertTrue(Hash::check('rahasiaBaru1', $akun->password));
        $this->get('/akun-saya/password-baru')->assertRedirect('/akun-saya');
        $this->get('/akun-saya')->assertOk()->assertInertia(fn ($page) => $page->component('Portal/Home')
            ->has('hasil', 1)
            ->where('hasil.0.nama', 'Budi Santoso'));
    }

    public function test_customers_with_the_same_phone_share_one_account_and_see_only_their_subscriptions(): void
    {
        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data())->assertOk();
        $this->postJson('/pelanggan', $this->data(['nama' => 'Budi Kantor', 'ip_address' => '10.12.10.6']))->assertOk();
        $this->postJson('/pelanggan', $this->data(['nama' => 'Orang Lain', 'tlp' => '089900001111', 'ip_address' => '10.12.10.7']))->assertOk();

        $akun = User::firstWhere('username', '081234567890');
        $this->assertSame(2, $akun->pelanggan()->count());
        $akun->update(['wajib_ganti_password' => false]);

        $this->actingAs($akun)->get('/akun-saya')->assertInertia(fn ($page) => $page
            ->has('hasil', 2)
            ->where('hasil.0.nama', 'Budi Kantor')
            ->where('hasil.1.nama', 'Budi Santoso'));
    }

    public function test_changing_phone_moves_customer_to_new_account_and_deactivates_unused_old_account(): void
    {
        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data())->assertOk();
        $pelanggan = Pelanggan::firstWhere('nama', 'Budi Santoso');
        $lama = $pelanggan->user;

        $this->putJson("/pelanggan/{$pelanggan->id}", $this->data(['tlp' => '085700002222', 'status' => 'aktif']))->assertOk();

        $this->assertSame('085700002222', $pelanggan->fresh()->user->username);
        $this->assertSame('nonaktif', $lama->fresh()->status);
    }

    public function test_unused_account_reclaimed_by_new_customer_is_reactivated_with_password_reset_to_phone(): void
    {
        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data())->assertOk();
        $pelanggan = Pelanggan::firstWhere('nama', 'Budi Santoso');
        $akun = $pelanggan->user;
        $akun->update(['password' => 'passwordLama1', 'wajib_ganti_password' => false]);
        $this->putJson("/pelanggan/{$pelanggan->id}", $this->data(['tlp' => '085700002222', 'status' => 'aktif']))->assertOk();

        $this->postJson('/pelanggan', $this->data(['nama' => 'Pemilik Baru', 'ip_address' => '10.12.10.9']))->assertOk();

        $akun->refresh();
        $this->assertSame(['aktif', 'Pemilik Baru', true], [$akun->status, $akun->name, $akun->wajib_ganti_password]);
        $this->assertTrue(Hash::check('081234567890', $akun->password));
    }

    public function test_permanently_deleting_the_last_customer_deactivates_the_account(): void
    {
        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data())->assertOk();
        $pelanggan = Pelanggan::firstWhere('nama', 'Budi Santoso');
        $pelanggan->update(['status' => 'berhenti']);

        $this->deleteJson("/pelanggan/{$pelanggan->id}", ['permanen' => 1])->assertOk();

        $this->assertSame('nonaktif', User::firstWhere('username', '081234567890')->status);
    }

    public function test_store_rejects_invalid_phone_and_phone_used_as_staff_username(): void
    {
        User::factory()->peran('Admin')->create(['username' => '081211112222']);

        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data(['tlp' => '12345']))
            ->assertStatus(422)->assertJsonValidationErrors(['tlp' => 'No. telepon harus nomor HP yang valid (10–15 digit, mis. 0812xxxxxxxx)']);
        $this->postJson('/pelanggan', $this->data(['tlp' => '0812-1111-2222']))
            ->assertStatus(422)->assertJsonValidationErrors(['tlp' => 'No. telepon ini sudah dipakai sebagai username user aplikasi']);
        $this->assertSame(0, Pelanggan::count());
    }

    public function test_customer_account_is_refused_on_staff_pages_even_if_its_role_is_given_permissions(): void
    {
        $akun = $this->akunPelanggan();
        $akun->update(['wajib_ganti_password' => false]);
        Role::findByName(AkunPelanggan::ROLE)->givePermissionTo(['dashboard.view', 'pelanggan.data.view']);

        $this->actingAs($akun)->get('/dashboard')->assertRedirect('/akun-saya');
        $this->get('/profil')->assertRedirect('/akun-saya');
        $this->getJson('/pelanggan')->assertForbidden();
        $this->postJson('/pelanggan', $this->data(['nama' => 'Sisipan', 'ip_address' => '10.12.10.50']))->assertForbidden();
        $this->assertSame(0, Pelanggan::where('nama', 'Sisipan')->count());
    }

    public function test_staff_cannot_open_customer_page(): void
    {
        $this->actingAs($this->admin)->get('/akun-saya')->assertForbidden();
    }

    public function test_user_management_hides_customer_accounts_and_refuses_customer_role(): void
    {
        $akun = $this->akunPelanggan();

        $this->actingAs($this->admin)->get('/user/manajemen')->assertInertia(fn ($page) => $page
            ->where('rows', fn ($rows) => collect($rows)->doesntContain('username', '081234567890'))
            ->where('roleOptions', fn ($opsi) => collect($opsi)->doesntContain('value', AkunPelanggan::ROLE)));
        $this->postJson('/user', ['nama' => 'X', 'username' => 'x.staf', 'role' => AkunPelanggan::ROLE, 'password' => 'rahasia123', 'status' => 'aktif'])
            ->assertStatus(422)->assertJsonValidationErrors(['role' => 'Role tidak valid']);
        $this->putJson("/user/{$akun->id}/status")->assertNotFound();
    }

    public function test_customer_role_is_hidden_and_locked_in_permission_matrix(): void
    {
        $role = Role::findByName(AkunPelanggan::ROLE);

        $this->actingAs($this->admin)->get('/user/hak-akses')->assertInertia(fn ($page) => $page
            ->where('roles', fn ($roles) => collect($roles)->doesntContain('nama', AkunPelanggan::ROLE)));
        $this->putJson("/user/role/{$role->id}", ['permissions' => ['dashboard.view']])->assertStatus(422);
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_customer_account_menu_lists_accounts_of_own_branch_only(): void
    {
        $this->akunPelanggan();
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Tasik']);
        $this->postJson('/pelanggan', $this->data(['nama' => 'Siti Tasik', 'tlp' => '085711110000', 'id_lokasi' => $lain->id, 'ip_address' => '10.12.10.8']))->assertOk();
        $mitra = User::factory()->peran('Admin')->create(['id_lokasi' => $this->lokasi->id]);

        $this->actingAs($mitra)->get('/user/akun-pelanggan')->assertOk()->assertInertia(fn ($page) => $page->component('User/AkunPelanggan')
            ->has('rows', 1)
            ->where('rows.0.username', '081234567890')
            ->where('rows.0.pelanggan.0.nama', 'Budi Santoso'));
    }

    public function test_reset_password_restores_phone_password_and_status_toggles(): void
    {
        $akun = $this->akunPelanggan();
        $akun->update(['password' => 'gantiSendiri1', 'wajib_ganti_password' => false]);

        $this->actingAs($this->admin)->putJson("/user/akun-pelanggan/{$akun->id}/reset-password")->assertOk();
        $this->putJson("/user/akun-pelanggan/{$akun->id}/status")->assertOk()->assertJsonPath('message', 'Akun Budi Santoso dinonaktifkan');

        $akun->refresh();
        $this->assertTrue(Hash::check('081234567890', $akun->password));
        $this->assertTrue($akun->wajib_ganti_password);
        $this->assertSame('nonaktif', $akun->status);
    }

    public function test_customer_account_actions_return_404_for_other_branch_or_staff_account(): void
    {
        $akun = $this->akunPelanggan();
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Tasik']);
        $mitraLain = User::factory()->peran('Admin')->create(['id_lokasi' => $lain->id]);

        $this->actingAs($mitraLain)->putJson("/user/akun-pelanggan/{$akun->id}/reset-password")->assertNotFound();
        $this->actingAs($this->admin)->putJson("/user/akun-pelanggan/{$mitraLain->id}/status")->assertNotFound();
        $this->assertSame('aktif', $mitraLain->fresh()->status);
    }

    public function test_role_without_permission_cannot_manage_customer_accounts(): void
    {
        $akun = $this->akunPelanggan();

        $this->actingAs(User::factory()->peran('Teknisi')->create())->putJson("/user/akun-pelanggan/{$akun->id}/reset-password")->assertForbidden();
    }

    public function test_customer_changes_password_later_only_after_confirming_the_old_one(): void
    {
        $akun = $this->akunPelanggan();
        $akun->update(['wajib_ganti_password' => false]);

        $this->actingAs($akun)->putJson('/akun-saya/password', ['password_lama' => 'salah', 'password' => 'rahasiaBaru1', 'password_confirmation' => 'rahasiaBaru1'])
            ->assertStatus(422)->assertJsonValidationErrors(['password_lama' => 'Password lama salah']);
        $this->putJson('/akun-saya/password', ['password_lama' => '081234567890', 'password' => 'rahasiaBaru1', 'password_confirmation' => 'rahasiaBaru1'])->assertOk();

        $this->assertTrue(Hash::check('rahasiaBaru1', $akun->fresh()->password));
    }

    public function test_backfill_command_creates_accounts_for_existing_customers_and_skips_invalid_phone(): void
    {
        $lama = Pelanggan::create(['nama' => 'Lama', 'tlp' => '0813-0000-1111', 'id_lokasi' => $this->lokasi->id, 'router_id' => $this->router->id]);
        $rusak = Pelanggan::create(['nama' => 'Rusak', 'tlp' => '-', 'id_lokasi' => $this->lokasi->id, 'router_id' => $this->router->id]);

        $this->artisan('pelanggan:buat-akun')->assertSuccessful();

        $this->assertSame('081300001111', $lama->fresh()->user->username);
        $this->assertNull($rusak->fresh()->user_id);
    }

    public function test_backfill_command_creates_account_without_branch_when_customer_branch_no_longer_exists(): void
    {
        $yatim = Pelanggan::create(['nama' => 'Pelanggan Lama', 'tlp' => '081300009999', 'id_lokasi' => 999, 'router_id' => $this->router->id]);

        $this->artisan('pelanggan:buat-akun')->assertSuccessful()->expectsOutputToContain('cabangnya tidak ditemukan');

        $akun = $yatim->fresh()->user;
        $this->assertSame(['081300009999', null], [$akun->username, $akun->id_lokasi]);
    }

    public function test_released_account_no_longer_blocks_deleting_its_branch(): void
    {
        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data())->assertOk();
        $pelanggan = Pelanggan::firstWhere('nama', 'Budi Santoso');
        $pelanggan->update(['status' => 'berhenti']);
        $this->deleteJson("/pelanggan/{$pelanggan->id}", ['permanen' => 1])->assertOk();

        $this->deleteJson("/cabang/{$this->lokasi->id}")->assertOk();
        $this->assertNull(User::firstWhere('username', '081234567890')->id_lokasi);
    }

    /** Akun pelanggan Budi (081234567890) yang dibuat lewat form Data Pelanggan. */
    private function akunPelanggan(): User
    {
        $this->actingAs($this->admin)->postJson('/pelanggan', $this->data())->assertOk();

        return User::firstWhere('username', '081234567890');
    }

    private function data(array $override = []): array
    {
        return [
            'nama' => 'Budi Santoso', 'tlp' => '081234567890', 'alamat' => 'Jl. Cikuray 1', 'jns_tagihan' => 'prabayar', 'skema_tagihan' => 'full',
            'id_lokasi' => $this->lokasi->id, 'router_id' => $this->router->id, 'jns_koneksi' => 'static', 'ip_address' => '10.12.10.5',
            'id_paket' => $this->paket->id, 'tgl_registrasi' => '2026-10-01', 'tgl_jatuh_tempo' => 10, 'ppn' => false,
            ...$override,
        ];
    }
}
