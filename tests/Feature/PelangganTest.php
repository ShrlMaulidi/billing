<?php

namespace Tests\Feature;

use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\Tunnel;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikGateway;
use App\Services\Mikrotik\RouterOsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PelangganTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gw;

    private FakeConnector $connector;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->gw = new FakeGateway;
        $this->connector = new FakeConnector($this->gw);
        $this->app->instance(MikrotikConnector::class, $this->connector);
        $this->router = Router::create([
            'nama_router' => 'CCR Pusat', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.10', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR',
        ]);
    }

    public function test_store_creates_customer_with_auto_id_and_unique_username_per_router(): void
    {
        $data = ['nama' => 'Budi Santoso', 'id_lokasi' => 1, 'router_id' => $this->router->id, 'username_pppoe' => 'budi', 'status' => 'aktif'];

        $this->postJson('/pelanggan', $data)->assertOk()->assertJsonPath('message', 'Pelanggan Budi Santoso ditambahkan');

        $p = Pelanggan::firstWhere('username_pppoe', 'budi');
        $this->assertSame(sprintf('P%05d', $p->id), $p->id_pelanggan);
        $this->assertSame('on', $p->status_langganan);

        $this->postJson('/pelanggan', [...$data, 'nama' => 'Budi Lain'])
            ->assertStatus(422)->assertJsonValidationErrors(['username_pppoe' => 'Username PPPoE sudah dipakai']);
        $this->postJson('/pelanggan', ['nama' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['nama', 'id_lokasi', 'router_id', 'username_pppoe', 'status']);
    }

    public function test_on_and_off_pages_only_show_matching_active_customers(): void
    {
        $this->pelanggan('budi');
        $this->pelanggan('sari', ['status_langganan' => 'off', 'profile_asal' => '10Mbps']);
        $this->pelanggan('lama', ['status' => 'berhenti', 'status_langganan' => 'off']);

        $this->get('/pelanggan')->assertInertia(fn ($page) => $page->component('Pelanggan/Index')->where('status', null)->has('rows', 3)
            ->where('jumlah', ['semua' => 3, 'on' => 1, 'off' => 1]));
        $this->get('/pelanggan/on')->assertInertia(fn ($page) => $page->where('status', 'on')->has('rows', 1)->where('rows.0.username_pppoe', 'budi'));
        $this->get('/pelanggan/off')->assertInertia(fn ($page) => $page->where('status', 'off')->has('rows', 1)->where('rows.0.username_pppoe', 'sari'));
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('kpi.total', 2)->where('kpi.on', 1)->where('kpi.off', 1));
    }

    public function test_off_isolates_customer_saves_original_profile_and_kicks_session(): void
    {
        $p = $this->pelanggan('budi');

        $this->postJson("/pelanggan/{$p->id}/isolir")
            ->assertOk()->assertJsonPath('message', 'Budi diisolir (20Mbps → ISOLIR), sesi aktif diputus');

        $this->assertSame('ISOLIR', $this->gw->secrets['budi']['profile']);
        $this->assertSame(['budi'], $this->gw->kicked);
        $p->refresh();
        $this->assertSame('off', $p->status_langganan);
        $this->assertSame('20Mbps', $p->profile_asal);
        $this->assertDatabaseHas('isolir_logs', ['pelanggan_id' => $p->id, 'aksi' => 'isolir', 'profile_dari' => '20Mbps', 'profile_ke' => 'ISOLIR', 'berhasil' => true]);
    }

    public function test_on_restores_original_profile(): void
    {
        $p = $this->pelanggan('budi');
        $this->postJson("/pelanggan/{$p->id}/isolir")->assertOk();
        $this->gw->active[] = 'budi'; // pelanggan login ulang dengan profile isolir

        $this->postJson("/pelanggan/{$p->id}/aktifkan")
            ->assertOk()->assertJsonPath('message', 'Budi diaktifkan kembali (ISOLIR → 20Mbps), sesi diputus agar login ulang');

        $this->assertSame('20Mbps', $this->gw->secrets['budi']['profile']);
        $p->refresh();
        $this->assertSame('on', $p->status_langganan);
        $this->assertNull($p->profile_asal);
    }

    public function test_off_fails_cleanly_when_isolir_profile_missing(): void
    {
        $p = $this->pelanggan('budi');
        $this->gw->profiles = ['default', '20Mbps'];

        $this->postJson("/pelanggan/{$p->id}/isolir")
            ->assertStatus(422)->assertJsonPath('message', "Profile isolir 'ISOLIR' belum ada di router, buat dulu di PPP > Profiles");

        $this->assertSame('20Mbps', $this->gw->secrets['budi']['profile']);
        $this->assertSame('on', $p->fresh()->status_langganan);
        $this->assertDatabaseHas('isolir_logs', ['pelanggan_id' => $p->id, 'aksi' => 'isolir', 'berhasil' => false]);
    }

    public function test_on_with_unknown_original_profile_requires_choice(): void
    {
        $p = $this->pelanggan('tono', ['status_langganan' => 'off']);

        $this->postJson("/pelanggan/{$p->id}/aktifkan")
            ->assertStatus(422)->assertJsonPath('message', 'Profile asal Tono tidak diketahui, pilih profile yang akan dipakai');

        $this->getJson("/mikrotik/router/{$this->router->id}/profiles")->assertOk()->assertJsonPath('data', ['default', '10Mbps', '20Mbps', 'ISOLIR']);
        $this->postJson("/pelanggan/{$p->id}/aktifkan", ['profile' => '10Mbps'])->assertOk();
        $this->assertSame('10Mbps', $this->gw->secrets['tono']['profile']);
        $this->assertSame('on', $p->fresh()->status_langganan);
    }

    public function test_on_when_router_profile_is_already_normal_only_syncs_status(): void
    {
        $p = $this->pelanggan('budi', ['status_langganan' => 'off']);

        $this->postJson("/pelanggan/{$p->id}/aktifkan")->assertOk()->assertJsonPath('message', 'Budi sudah aktif dengan profile 20Mbps');

        $this->assertSame('on', $p->fresh()->status_langganan);
        $this->assertSame([], $this->gw->kicked);
    }

    public function test_unknown_pppoe_username_is_reported(): void
    {
        $p = $this->pelanggan('tidakada');

        $this->postJson("/pelanggan/{$p->id}/isolir")
            ->assertStatus(422)->assertJsonPath('message', "PPP Secret 'tidakada' tidak ditemukan di router CCR Pusat");
    }

    public function test_deleting_router_keeps_customers_but_blocks_on_off(): void
    {
        $p = $this->pelanggan('budi');

        $this->deleteJson("/mikrotik/router/{$this->router->id}")->assertOk();

        $this->assertNull($p->fresh()->router_id);
        $this->postJson("/pelanggan/{$p->id}/isolir")
            ->assertStatus(422)->assertJsonPath('message', 'Budi belum dihubungkan ke router, edit data pelanggan dulu');
    }

    public function test_connection_failure_marks_router_offline(): void
    {
        $this->connector->gagal = RouterOsException::koneksi('Tidak dapat terhubung ke 203.0.113.10:8728 (Connection timed out)');

        $this->postJson("/mikrotik/router/{$this->router->id}/test")
            ->assertStatus(422)->assertJsonPath('message', 'CCR Pusat: Tidak dapat terhubung ke 203.0.113.10:8728 (Connection timed out)');

        $router = $this->router->fresh();
        $this->assertSame('offline', $router->status_koneksi);
        $this->assertStringContainsString('timed out', $router->last_error);
    }

    public function test_test_connection_stores_identity(): void
    {
        $this->postJson("/mikrotik/router/{$this->router->id}/test")
            ->assertOk()->assertJsonPath('message', 'Terhubung ke MSS-Pusat (RouterOS 7.16, CCR1036, uptime 3d4h)');

        $this->assertSame('MSS-Pusat', $this->router->fresh()->identity);
    }

    public function test_router_crud_keeps_password_secret_and_tunnel_mode_uses_tunnel_ip(): void
    {
        $tunnel = Tunnel::create(['nama_tunnel' => 'tnl-garut', 'protokol' => 'L2TP', 'server' => 'vpn.mss.net.id', 'username' => 'garut', 'password' => 'x', 'ip_tunnel' => '10.10.10.2', 'status' => 'aktif']);

        $this->postJson('/mikrotik/router', ['nama_router' => 'RB Garut', 'mode_koneksi' => 'tunnel'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tunnel_id' => 'Tunnel wajib diisi', 'password' => 'Password API wajib diisi']);

        $this->postJson('/mikrotik/router', [
            'nama_router' => 'RB Garut', 'mode_koneksi' => 'tunnel', 'tunnel_id' => $tunnel->id, 'host' => '1.2.3.4', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR',
        ])->assertOk();

        $router = Router::where('nama_router', 'RB Garut')->first();
        $this->assertNull($router->host);
        $this->assertSame('10.10.10.2', $router->alamatApi());
        $this->assertNotSame('rahasia', $router->getRawOriginal('password'));
        $this->get('/kelola-data/router')->assertOk()->assertDontSee('rahasia');

        // Password kosong saat edit = tidak berubah
        $this->putJson("/mikrotik/router/{$router->id}", [...$router->only(['nama_router', 'mode_koneksi', 'tunnel_id', 'port_api', 'username', 'profile_isolir']), 'password' => ''])->assertOk();
        $this->assertSame('rahasia', $router->fresh()->password);

        $this->deleteJson("/mikrotik/tunnel/{$tunnel->id}")->assertStatus(422);
        $this->assertDatabaseHas('tunnels', ['id' => $tunnel->id]);
    }

    private function pelanggan(string $username, array $attrs = []): Pelanggan
    {
        return Pelanggan::create([
            'nama' => ucfirst($username), 'id_lokasi' => 1, 'router_id' => $this->router->id, 'username_pppoe' => $username, ...$attrs,
        ]);
    }
}

/** Connector tiruan: selalu memberi FakeGateway, atau melempar $gagal bila diisi. */
class FakeConnector extends MikrotikConnector
{
    public ?RouterOsException $gagal = null;

    public function __construct(private FakeGateway $gw) {}

    public function connect(Router $router): MikrotikGateway
    {
        return $this->gagal ? throw $this->gagal : $this->gw;
    }
}

/** Mikrotik tiruan: menyimpan PPP Secret di memori dan mencatat sesi yang diputus. */
class FakeGateway implements MikrotikGateway
{
    public array $secrets = [
        'budi' => ['id' => '*1', 'name' => 'budi', 'profile' => '20Mbps', 'service' => 'pppoe', 'remote_address' => null, 'comment' => null, 'disabled' => false],
        'sari' => ['id' => '*2', 'name' => 'sari', 'profile' => '10Mbps', 'service' => 'pppoe', 'remote_address' => null, 'comment' => null, 'disabled' => false],
        'tono' => ['id' => '*3', 'name' => 'tono', 'profile' => 'ISOLIR', 'service' => 'pppoe', 'remote_address' => null, 'comment' => null, 'disabled' => false],
    ];

    public array $profiles = ['default', '10Mbps', '20Mbps', 'ISOLIR'];

    public array $active = ['budi'];

    public array $kicked = [];

    public function info(): array
    {
        return ['identity' => 'MSS-Pusat', 'version' => '7.16', 'board' => 'CCR1036', 'uptime' => '3d4h'];
    }

    public function findSecret(string $name): ?array
    {
        return $this->secrets[$name] ?? null;
    }

    public function profiles(): array
    {
        return $this->profiles;
    }

    public function setSecretProfile(string $id, string $profile): void
    {
        foreach ($this->secrets as $name => $s) {
            if ($s['id'] === $id) {
                $this->secrets[$name]['profile'] = $profile;
            }
        }
    }

    public function removeActive(string $name): int
    {
        if (! in_array($name, $this->active, true)) {
            return 0;
        }
        $this->active = array_values(array_diff($this->active, [$name]));
        $this->kicked[] = $name;

        return 1;
    }
}
