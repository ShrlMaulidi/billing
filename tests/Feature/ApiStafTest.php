<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\User;
use App\Support\HakAkses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiStafTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $garut;

    private Lokasi $cimahi;

    private Pelanggan $budi;

    private Pelanggan $citra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $this->garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '20M']);
        $this->budi = Pelanggan::create(['nama' => 'Budi', 'tlp' => '081234567890', 'username_pppoe' => 'budi', 'id_lokasi' => $this->garut->id, 'id_paket' => $paket->id, 'tgl_registrasi' => '2026-08-01']);
        $this->citra = Pelanggan::create(['nama' => 'Citra', 'username_pppoe' => 'citra', 'id_lokasi' => $this->cimahi->id, 'id_paket' => $paket->id, 'tgl_registrasi' => '2026-08-01']);
    }

    public function test_staff_logs_in_with_username_and_gets_device_token_with_permissions(): void
    {
        User::factory()->peran('Teknisi')->create(['username' => 'agus', 'password' => 'rahasia123']);

        $res = $this->postJson('/api/v1/login', ['username' => 'agus', 'password' => 'rahasia123', 'device_name' => 'HP Agus'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', '2026-11-14T10:00:00+00:00')
            ->assertJsonPath('user.role', 'Teknisi')
            ->assertJsonPath('user.permissions', HakAkses::bawaan('Teknisi'));

        $this->assertSame(['staf'], PersonalAccessToken::sole()->abilities);
        $this->withToken($res->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.username', 'agus');
    }

    public function test_wrong_password_and_inactive_account_are_rejected(): void
    {
        User::factory()->create(['username' => 'agus', 'password' => 'rahasia123', 'status' => 'nonaktif']);

        $this->postJson('/api/v1/login', ['username' => 'agus', 'password' => 'salah', 'device_name' => 'HP'])
            ->assertStatus(422)->assertJsonValidationErrors(['username' => 'Username/email atau kata sandi salah.']);
        $this->postJson('/api/v1/login', ['username' => 'agus', 'password' => 'rahasia123', 'device_name' => 'HP'])
            ->assertStatus(422)->assertJsonValidationErrors(['username' => 'Akun ini sedang dinonaktifkan. Hubungi Super Admin.']);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_failed_web_logins_also_lock_api_login(): void
    {
        User::factory()->create(['username' => 'agus', 'password' => 'rahasia123']);
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['username' => 'agus', 'password' => 'salah']);
        }

        $this->postJson('/api/v1/login', ['username' => 'agus', 'password' => 'rahasia123', 'device_name' => 'HP'])
            ->assertStatus(422)->assertJsonValidationErrors('username');
        $this->assertStringContainsString('Terlalu banyak percobaan login', $this->postJson('/api/v1/login', ['username' => 'agus', 'password' => 'rahasia123', 'device_name' => 'HP'])->json('errors.username.0'));
    }

    public function test_request_without_valid_token_gets_401_json(): void
    {
        $this->getJson('/api/v1/pelanggan')->assertUnauthorized()->assertJsonPath('message', 'Sesi berakhir atau token tidak valid, silakan login kembali');
        $this->withToken('1|palsu')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_customer_list_follows_status_permission_search_and_branch(): void
    {
        $teknisi = $this->token(User::factory()->peran('Teknisi')->create());
        $this->withToken($teknisi)->getJson('/api/v1/pelanggan')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nama', 'Budi')->assertJsonPath('data.0.tagihan.nominal', 200000)->assertJsonPath('data.0.tagihan.lunas', false)
            ->assertJsonPath('meta.total', 2);
        $this->withToken($teknisi)->getJson('/api/v1/pelanggan?cari=citr')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama', 'Citra');
        // Teknisi tidak punya izin tab Lunas
        $this->withToken($teknisi)->getJson('/api/v1/pelanggan?status=lunas')->assertForbidden()->assertJsonPath('message', 'Anda tidak memiliki akses ke fitur ini');

        $this->app['auth']->forgetGuards();
        $mitraGarut = $this->token(User::factory()->peran('Admin')->create(['id_lokasi' => $this->garut->id]));
        // Filter cabang dari user cabang diabaikan: tetap cabangnya sendiri
        $this->withToken($mitraGarut)->getJson("/api/v1/pelanggan?cabang={$this->cimahi->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama', 'Budi');
        $this->withToken($mitraGarut)->getJson("/api/v1/pelanggan/{$this->citra->id}")->assertNotFound();
        $this->withToken($mitraGarut)->getJson("/api/v1/pelanggan/{$this->budi->id}")->assertOk()->assertJsonPath('data.cabang', 'MSS Garut');
    }

    public function test_payment_through_api_uses_the_same_rules_as_web(): void
    {
        $token = $this->token(User::factory()->peran('Admin')->create());

        $this->withToken($token)->postJson("/api/v1/pelanggan/{$this->budi->id}/bayar", ['bulan' => 10, 'tahun' => 2026, 'metode' => 'cash', 'jml_bayar' => 1])
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'sebesar Rp200.000'));
        $this->withToken($token)->postJson("/api/v1/pelanggan/{$this->budi->id}/bayar", ['bulan' => 10, 'tahun' => 2026, 'metode' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors(['bulan' => 'Periode ini sudah dibayar']);

        $pembayaran = Pembayaran::sole();
        $this->withToken($token)->getJson('/api/v1/pelanggan?status=lunas')->assertJsonCount(1, 'data')->assertJsonPath('data.0.tagihan.pembayaran_id', $pembayaran->id);
        $this->withToken($token)->getJson("/api/v1/pembayaran/{$pembayaran->id}")->assertOk()
            ->assertJsonPath('data.no_invoice', $pembayaran->no_invoice)->assertJsonPath('data.jml_bayar', 200000)->assertJsonPath('data.cabang.nama_lokasi', 'MSS Garut');
        $this->withToken($token)->getJson("/api/v1/pelanggan/{$this->budi->id}/tagihan")->assertOk()
            ->assertJsonPath('data.nominal', 200000)->assertJsonPath('data.pembayaran_id', $pembayaran->id);
    }

    public function test_sales_cannot_isolate_and_branch_user_cannot_record_setoran(): void
    {
        $sales = $this->token(User::factory()->peran('Sales')->create());
        $this->withToken($sales)->postJson("/api/v1/pelanggan/{$this->budi->id}/isolir")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $mitra = $this->token(User::factory()->peran('Admin')->create(['id_lokasi' => $this->garut->id]));
        $this->withToken($mitra)->postJson('/api/v1/transaksi/setor-kewajiban', ['id_lokasi' => $this->garut->id])->assertForbidden();
        $this->withToken($mitra)->getJson('/api/v1/transaksi/penarikan-saldo')->assertOk()
            ->assertJsonPath('periode_opsi.0', '2026-10-01')->assertJsonStructure(['data', 'meta', 'saldo_tersedia' => [$this->garut->id]]);
    }

    public function test_mitra_dashboard_shows_own_branch_balance(): void
    {
        Pembayaran::create(['pelanggan_id' => $this->budi->id, 'id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nama_pelanggan' => 'Budi', 'nama_paket' => 'Home', 'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => now()]);
        $token = $this->token(User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]));

        $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('kpi.total', 1)->assertJsonPath('kpi.lunas', 1)->assertJsonPath('kpi.pemasukan_bulan', 200000)
            ->assertJsonPath('saldo_mitra.nama_cabang', 'MSS Garut')->assertJsonPath('saldo_mitra.pemasukan', 200000);
    }

    public function test_customer_token_cannot_use_staff_endpoints(): void
    {
        $token = $this->budi->createToken('HP Budi', ['pelanggan'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/pelanggan')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_deactivated_user_token_is_revoked(): void
    {
        $agus = User::factory()->peran('Teknisi')->create();
        $token = $this->token($agus);
        $agus->update(['status' => 'nonaktif']);

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Akun Anda dinonaktifkan');
        $this->assertSame(0, $agus->tokens()->count());
    }

    public function test_deactivating_or_changing_password_from_user_menu_revokes_mobile_tokens(): void
    {
        $agus = User::factory()->peran('Teknisi')->create();
        $this->token($agus);

        $this->actingAs(User::factory()->create())->putJson("/user/{$agus->id}/status")->assertOk();

        $this->assertSame(0, $agus->tokens()->count());
    }

    public function test_logout_revokes_only_this_device(): void
    {
        $agus = User::factory()->peran('Teknisi')->create();
        $hp = $this->token($agus);
        $this->token($agus);

        $this->withToken($hp)->postJson('/api/v1/logout')->assertOk();

        $this->assertSame(1, $agus->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($hp)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function token(User $user): string
    {
        return $user->createToken('test', ['staf'])->plainTextToken;
    }
}
