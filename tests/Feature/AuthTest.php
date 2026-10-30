<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_and_cannot_call_mikrotik_actions(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/pelanggan/on')->assertRedirect('/login');

        $router = Router::create(['nama_router' => 'R', 'mode_koneksi' => 'langsung', 'host' => '10.0.0.1', 'port_api' => 8728, 'username' => 'u', 'password' => 'p', 'profile_isolir' => 'ISOLIR']);
        $this->postJson("/mikrotik/router/{$router->id}/test")->assertUnauthorized();
        $this->postJson('/mikrotik/router', [])->assertUnauthorized();
    }

    public function test_user_can_login_with_username_and_is_sent_to_dashboard(): void
    {
        $user = User::factory()->create(['username' => 'superadmin', 'password' => 'rahasia123']);

        $this->post('/login', ['username' => 'superadmin', 'password' => 'rahasia123'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $this->get('/login')->assertRedirect('/dashboard');
    }

    public function test_user_can_login_with_email(): void
    {
        $user = User::factory()->create(['email' => 'sahrul1@gmail.com', 'password' => 'sahrul123']);

        $this->post('/login', ['username' => 'sahrul1@gmail.com', 'password' => 'sahrul123'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_seeder_creates_login_account(): void
    {
        $this->seed();
        $this->seed(); // aman dijalankan berulang

        $this->assertSame(1, User::where('email', 'sahrul1@gmail.com')->count());
        $this->post('/login', ['username' => 'sahrul1@gmail.com', 'password' => 'sahrul123'])->assertRedirect('/dashboard');
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'superadmin', 'password' => 'rahasia123']);

        $this->post('/login', ['username' => 'superadmin', 'password' => 'salah'])
            ->assertSessionHasErrors(['username' => 'Username/email atau kata sandi salah.']);
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        User::factory()->create(['username' => 'superadmin', 'password' => 'rahasia123']);

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'superadmin', 'password' => 'salah']);
        }
        // Kata sandi benar pun ditolak selama masa jeda
        $this->post('/login', ['username' => 'superadmin', 'password' => 'rahasia123'])->assertSessionHasErrors('username');
        $this->assertStringContainsString('Terlalu banyak percobaan login', session('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_role_without_app_access_cannot_log_in(): void
    {
        User::factory()->create(['username' => 'agus', 'password' => 'rahasia123', 'role' => 'Sales']);

        $this->post('/login', ['username' => 'agus', 'password' => 'rahasia123'])
            ->assertSessionHasErrors(['username' => 'Akun ini belum memiliki akses ke aplikasi. Hubungi Super Admin.']);

        $this->assertGuest();
    }

    public function test_one_ip_guessing_many_usernames_is_locked_out(): void
    {
        User::factory()->create(['username' => 'superadmin', 'password' => 'rahasia123']);

        foreach (range(1, 20) as $i) {
            $this->post('/login', ['username' => "tebakan$i", 'password' => 'salah']);
        }
        $this->post('/login', ['username' => 'superadmin', 'password' => 'rahasia123'])->assertSessionHasErrors('username');

        $this->assertStringContainsString('Terlalu banyak percobaan login', session('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_logout_ends_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_shared_props_use_logged_in_user(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Sahrul', 'username' => 'sahrul', 'role' => 'Admin']));

        $this->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('auth.user.nama', 'Sahrul')->where('auth.user.username', 'sahrul')->where('auth.user.role', 'Admin'));
    }

    private const FORWARDED = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'billingv2.sahrulmaulidi.online', 'X-Forwarded-Port' => '443'];

    public function test_https_from_local_proxy_is_trusted(): void
    {
        // cloudflared / reverse proxy di jaringan lokal: redirect memakai https + domain asli
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders(self::FORWARDED)
            ->get('/')->assertRedirect('https://billingv2.sahrulmaulidi.online/login');
    }

    public function test_forwarded_headers_from_public_ip_are_ignored(): void
    {
        $location = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeaders(self::FORWARDED)
            ->get('/')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsNotWith('https://', $location);
        $this->assertStringNotContainsString('billingv2', $location);
    }

    public function test_user_create_command_creates_and_resets_password(): void
    {
        $this->artisan('user:create', ['username' => 'superadmin', '--name' => 'Sahrul'])
            ->expectsQuestion('Kata sandi (min. 8 karakter)', 'rahasia123')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia123')
            ->assertSuccessful();
        $this->post('/login', ['username' => 'superadmin', 'password' => 'rahasia123'])->assertRedirect('/dashboard');
        $this->post('/logout');

        $this->artisan('user:create', ['username' => 'superadmin'])
            ->expectsConfirmation('User superadmin sudah ada. Reset kata sandinya?', 'yes')
            ->expectsQuestion('Kata sandi (min. 8 karakter)', 'sandibaru99')
            ->expectsQuestion('Ulangi kata sandi', 'sandibaru99')
            ->assertSuccessful();
        $this->post('/login', ['username' => 'superadmin', 'password' => 'sandibaru99'])->assertRedirect('/dashboard');

        $this->artisan('user:create', ['username' => 'baru'])
            ->expectsQuestion('Kata sandi (min. 8 karakter)', 'pendek')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['username' => 'baru']);
    }
}
