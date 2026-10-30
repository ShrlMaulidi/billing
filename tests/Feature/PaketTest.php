<?php

namespace Tests\Feature;

use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\RouterOsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeConnector;
use Tests\Fakes\FakeGateway;
use Tests\TestCase;

class PaketTest extends TestCase
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

    public function test_store_saves_package_and_creates_profile_with_pool_on_router(): void
    {
        $this->postJson('/paket', $this->data(['buat_pool' => true, 'nama_pool' => 'pool-30m', 'range_pool' => '10.10.3.2-10.10.3.254']))
            ->assertOk()
            ->assertJsonPath('message', 'Paket Ultra 30 Mbps ditambahkan. CCR Pusat: profile UP-30M dibuat')
            ->assertJsonPath('type', 'success');

        $this->assertSame('10.10.3.2-10.10.3.254', $this->gw->pools['pool-30m']);
        $this->assertSame(
            ['jenis' => 'pppoe', 'attributes' => ['rate-limit' => '30M/30M', 'local-address' => '10.10.3.1', 'remote-address' => 'pool-30m']],
            $this->gw->savedProfiles['UP-30M'],
        );
        $paket = Paket::firstWhere('nama_paket', 'Ultra 30 Mbps');
        $this->assertNotNull($paket->synced_at);
        $this->assertNull($paket->sync_error);
        $this->get('/kelola-data/paket')->assertInertia(fn ($page) => $page->component('KelolaData/Paket')->has('rows', 1)
            ->where('rows.0.profile_mikrotik', 'UP-30M')->where('rows.0.router', null));
    }

    public function test_router_profile_dropdown_lists_existing_profiles_without_isolir_profile(): void
    {
        $this->getJson("/paket/profile-router/{$this->router->id}?jenis=pppoe")->assertOk()->assertExactJson(['data' => [
            ['name' => 'default', 'rate_limit' => null], ['name' => '10Mbps', 'rate_limit' => null], ['name' => '20Mbps', 'rate_limit' => '20M/20M'],
        ]]);
        $this->getJson("/paket/profile-router/{$this->router->id}?jenis=hotspot")->assertOk()->assertJsonPath('data.1', ['name' => 'HS-1H', 'rate_limit' => '2M/2M']);
    }

    public function test_sync_mode_links_existing_router_profile_without_changing_router(): void
    {
        $this->postJson('/paket', $this->data(['buat_profile' => false, 'router_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['router_id' => 'Router wajib diisi']);

        $this->postJson('/paket', $this->data(['buat_profile' => false, 'router_id' => $this->router->id, 'profile_mikrotik' => '20Mbps']))
            ->assertOk()->assertJsonPath('type', 'success')
            ->assertJsonPath('message', 'Paket Ultra 30 Mbps ditambahkan. CCR Pusat: profile 20Mbps terhubung');

        $this->assertSame([], $this->gw->savedProfiles);
        $paket = Paket::firstWhere('nama_paket', 'Ultra 30 Mbps');
        // Pengaturan profile mengikuti router, bukan isian form
        $this->assertSame('20M/20M', $paket->rate_limit);
        $this->assertNull($paket->local_address);
        $this->assertNotNull($paket->synced_at);
    }

    public function test_sync_mode_warns_when_profile_is_missing_on_router(): void
    {
        $this->postJson('/paket', $this->data(['buat_profile' => false, 'router_id' => $this->router->id, 'profile_mikrotik' => 'ISOLIR']))
            ->assertOk()->assertJsonPath('type', 'warning')
            ->assertJsonPath('message', 'Paket Ultra 30 Mbps ditambahkan. CCR Pusat: profile ISOLIR tidak ada di router');

        $this->assertSame('CCR Pusat: profile ISOLIR tidak ada di router', Paket::firstWhere('nama_paket', 'Ultra 30 Mbps')->sync_error);
    }

    public function test_router_failure_keeps_package_and_returns_warning(): void
    {
        $this->connector->gagal = RouterOsException::koneksi('Router tidak merespons (timeout)');

        $this->postJson('/paket', $this->data())
            ->assertOk()->assertJsonPath('type', 'warning')
            ->assertJsonPath('message', 'Paket Ultra 30 Mbps ditambahkan. CCR Pusat: Router tidak merespons (timeout)');

        $paket = Paket::firstWhere('nama_paket', 'Ultra 30 Mbps');
        $this->assertNull($paket->synced_at);
        $this->assertSame('CCR Pusat: Router tidak merespons (timeout)', $paket->sync_error);

        $this->connector->gagal = null;
        $this->postJson("/paket/{$paket->id}/sinkron")->assertOk()->assertJsonPath('message', 'CCR Pusat: profile UP-30M dibuat');
        $this->assertNull($paket->fresh()->sync_error);
    }

    public function test_profile_named_like_isolir_profile_is_never_overwritten(): void
    {
        $this->postJson('/paket', $this->data(['profile_mikrotik' => 'ISOLIR']))
            ->assertOk()->assertJsonPath('type', 'warning')
            ->assertJsonPath('message', "Paket Ultra 30 Mbps ditambahkan. CCR Pusat: Profile 'ISOLIR' adalah profile isolir router CCR Pusat, pakai nama lain");

        $this->assertArrayNotHasKey('ISOLIR', $this->gw->savedProfiles);
    }

    public function test_sync_without_routers_is_rejected(): void
    {
        $paket = Paket::create($this->data(['buat_profile' => false]));
        $this->router->delete();

        $this->postJson("/paket/{$paket->id}/sinkron")
            ->assertStatus(422)->assertJsonPath('message', 'Belum ada router untuk menyinkronkan profile');
    }

    public function test_validation_rejects_invalid_input(): void
    {
        $this->postJson('/paket', ['nama_paket' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['nama_paket', 'harga', 'jenis', 'jns_koneksi', 'profile_mikrotik']);

        $this->postJson('/paket', $this->data(['rate_limit' => '30M; /system reset', 'buat_pool' => true, 'filter_lokasi' => true, 'id_lokasi' => null]))
            ->assertStatus(422)->assertJsonValidationErrors([
                'rate_limit' => 'Format Rate limit tidak valid', 'nama_pool' => 'Nama pool wajib diisi',
                'range_pool' => 'Range IP pool wajib diisi', 'id_lokasi' => 'Cabang wajib diisi',
            ]);
    }

    public function test_package_used_by_customers_cannot_be_deleted(): void
    {
        $paket = Paket::create($this->data(['buat_profile' => false]));
        Pelanggan::create(['nama' => 'Budi', 'id_lokasi' => 1, 'router_id' => $this->router->id, 'username_pppoe' => 'budi', 'id_paket' => $paket->id]);

        $this->deleteJson("/paket/{$paket->id}")
            ->assertStatus(422)->assertJsonPath('message', 'Paket Ultra 30 Mbps masih dipakai pelanggan, pindahkan pelanggan ke paket lain dulu');
        $this->assertModelExists($paket);
    }

    public function test_on_uses_package_profile_when_original_profile_is_unknown(): void
    {
        $paket = Paket::create($this->data(['profile_mikrotik' => '10Mbps', 'buat_profile' => false]));
        // Diisolir langsung di router: profile asal tidak tersimpan di billing
        $p = Pelanggan::create(['nama' => 'Tono', 'id_lokasi' => 1, 'router_id' => $this->router->id, 'username_pppoe' => 'tono', 'id_paket' => $paket->id, 'status_langganan' => 'off']);

        $this->postJson("/pelanggan/{$p->id}/aktifkan")
            ->assertOk()->assertJsonPath('message', 'Tono diaktifkan kembali (ISOLIR → 10Mbps)');

        $this->assertSame('10Mbps', $this->gw->secrets['tono']['profile']);
        $this->assertSame('on', $p->fresh()->status_langganan);
        $this->get('/pelanggan/on')->assertInertia(fn ($page) => $page->where('rows.0.paket', 'Ultra 30 Mbps')->where('rows.0.profile_paket', '10Mbps'));
    }

    private function data(array $attrs = []): array
    {
        return [
            'nama_paket' => 'Ultra 30 Mbps', 'keterangan' => 'Up to 30 Mbps', 'harga' => 275000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe',
            'profile_mikrotik' => 'UP-30M', 'rate_limit' => '30M/30M', 'local_address' => '10.10.3.1', 'buat_profile' => true, 'buat_pool' => false,
            ...$attrs,
        ];
    }
}
