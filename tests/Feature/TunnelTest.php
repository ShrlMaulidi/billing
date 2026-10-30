<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tunnel' => ['server' => '38.44.252.4', 'subnet' => '10.10.10.0/24', 'gateway' => '10.10.10.1']]);
        $this->actingAs(User::factory()->create());
    }

    public function test_store_creates_radius_account_with_fixed_tunnel_ip(): void
    {
        $this->postJson('/mikrotik/tunnel', $this->data())->assertOk()->assertJsonPath('message', 'Tunnel tnl-garut ditambahkan');

        $this->assertSame(['L2TP'], Tunnel::pluck('protokol')->all());
        $this->assertSame(
            [['Cleartext-Password', ':=', 'rahasia123']],
            $this->rows('radcheck', 'garut01'),
        );
        $this->assertSame(
            [['Framed-IP-Address', ':=', '10.10.10.2'], ['Acct-Interim-Interval', ':=', '60']],
            $this->rows('radreply', 'garut01'),
        );
    }

    public function test_update_moves_account_to_new_username_and_inactive_tunnel_is_rejected(): void
    {
        $this->postJson('/mikrotik/tunnel', $this->data())->assertOk();
        $tunnel = Tunnel::first();

        // Password kosong saat edit = tidak berubah
        $this->putJson("/mikrotik/tunnel/{$tunnel->id}", $this->data(['username' => 'garut02', 'password' => '', 'ip_tunnel' => '10.10.10.9', 'status' => 'tidak_aktif']))->assertOk();

        $this->assertSame([], $this->rows('radcheck', 'garut01'));
        $this->assertSame([], $this->rows('radreply', 'garut01'));
        $this->assertSame([['Cleartext-Password', ':=', 'rahasia123'], ['Auth-Type', ':=', 'Reject']], $this->rows('radcheck', 'garut02'));
        $this->assertSame(['Framed-IP-Address', ':=', '10.10.10.9'], $this->rows('radreply', 'garut02')[0]);
    }

    public function test_destroy_removes_radius_account_unless_tunnel_is_used_by_router(): void
    {
        $this->postJson('/mikrotik/tunnel', $this->data())->assertOk();
        $tunnel = Tunnel::first();
        $router = Router::create([
            'nama_router' => 'RB Garut', 'mode_koneksi' => 'tunnel', 'tunnel_id' => $tunnel->id, 'port_api' => 8728,
            'username' => 'billing', 'password' => 'x', 'profile_isolir' => 'ISOLIR',
        ]);

        $this->deleteJson("/mikrotik/tunnel/{$tunnel->id}")->assertStatus(422);
        $this->assertCount(1, $this->rows('radcheck', 'garut01'));

        $router->delete();
        $this->deleteJson("/mikrotik/tunnel/{$tunnel->id}")->assertOk();
        $this->assertSame([], $this->rows('radcheck', 'garut01'));
        $this->assertSame([], $this->rows('radreply', 'garut01'));
    }

    public function test_validation_keeps_tunnel_ip_inside_subnet_and_usernames_unique(): void
    {
        DB::table('radcheck')->insert(['username' => 'user_test', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'x']);
        $this->postJson('/mikrotik/tunnel', $this->data())->assertOk();

        $this->postJson('/mikrotik/tunnel', $this->data(['ip_tunnel' => '10.10.10.1', 'username' => 'user_test']))
            ->assertStatus(422)->assertJsonValidationErrors([
                'ip_tunnel' => '10.10.10.1 adalah IP server tunnel, pakai IP lain',
                'username' => 'Username sudah dipakai akun RADIUS lain',
            ]);
        $this->postJson('/mikrotik/tunnel', $this->data(['ip_tunnel' => '192.168.1.2', 'username' => 'garut 01']))
            ->assertStatus(422)->assertJsonValidationErrors([
                'ip_tunnel' => 'IP router di tunnel harus di dalam jaringan 10.10.10.0/24',
                'username' => 'Format Username tidak valid',
            ]);
        $this->postJson('/mikrotik/tunnel', $this->data())
            ->assertStatus(422)->assertJsonValidationErrors([
                'ip_tunnel' => 'IP router di tunnel sudah dipakai',
                'username' => 'Username sudah dipakai',
            ]);
    }

    public function test_index_shows_online_session_and_suggests_next_free_ip(): void
    {
        $this->postJson('/mikrotik/tunnel', $this->data())->assertOk();
        $this->postJson('/mikrotik/tunnel', $this->data(['nama_tunnel' => 'tnl-cianjur', 'username' => 'cianjur01', 'ip_tunnel' => '10.10.10.3']))->assertOk();
        DB::table('radacct')->insert([
            ['acctuniqueid' => 'a1', 'username' => 'garut01', 'framedipaddress' => '10.10.10.2', 'callingstationid' => '103.1.2.3', 'acctstarttime' => '2026-10-30 10:00:00', 'acctstoptime' => '2026-10-30 10:30:00'],
            ['acctuniqueid' => 'a2', 'username' => 'garut01', 'framedipaddress' => '10.10.10.2', 'callingstationid' => '103.1.2.3', 'acctstarttime' => '2026-10-30 11:00:00', 'acctstoptime' => null],
        ]);

        $this->get('/kelola-data/tunnel')->assertInertia(fn ($page) => $page->component('KelolaData/Tunnel')
            ->where('rows.0.nama_tunnel', 'tnl-cianjur')->where('rows.0.sesi', null)
            ->where('rows.1.sesi.acctstarttime', '2026-10-30 11:00:00')
            ->where('saran', ['server' => '38.44.252.4', 'ip_tunnel' => '10.10.10.4'])
            ->missing('rows.1.password'));
    }

    public function test_script_dials_l2tp_with_escaped_credentials(): void
    {
        $this->postJson('/mikrotik/tunnel', $this->data(['password' => 'a"b$c']))->assertOk();

        $script = $this->getJson('/mikrotik/tunnel/'.Tunnel::first()->id.'/script')->assertOk()->json('script');

        $this->assertStringContainsString('/interface l2tp-client add name="tnl-garut" connect-to="38.44.252.4" user="garut01" password="a\"b\$c" allow=chap', $script);
    }

    private function data(array $attrs = []): array
    {
        return [
            'nama_tunnel' => 'tnl-garut', 'server' => '38.44.252.4', 'username' => 'garut01', 'password' => 'rahasia123',
            'ip_tunnel' => '10.10.10.2', 'status' => 'aktif', ...$attrs,
        ];
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    private function rows(string $table, string $username): array
    {
        return DB::table($table)->where('username', $username)->orderBy('id')->get()
            ->map(fn ($r) => [$r->attribute, $r->op, $r->value])->all();
    }
}
