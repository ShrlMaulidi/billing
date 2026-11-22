<?php

namespace Tests\Feature;

use App\Models\Lokasi;
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

    private Lokasi $lokasi;

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
        $this->lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
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

    public function test_branch_user_cannot_read_profiles_of_another_branch_router(): void
    {
        $garut = $this->lokasi;
        $cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $this->router->update(['id_lokasi' => $cimahi->id]);
        $admin = User::factory()->peran('Admin')->create(['id_lokasi' => $garut->id]);

        $this->actingAs($admin)->getJson("/paket/profile-router/{$this->router->id}?jenis=pppoe")->assertNotFound();
        $this->router->update(['id_lokasi' => $garut->id]);
        $this->getJson("/paket/profile-router/{$this->router->id}?jenis=pppoe")->assertOk();
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

        $this->postJson('/paket', $this->data(['rate_limit' => '30M; /system reset', 'buat_pool' => true, 'id_lokasi' => null]))
            ->assertStatus(422)->assertJsonValidationErrors([
                'rate_limit' => 'Format Rate limit tidak valid', 'nama_pool' => 'Nama pool wajib diisi',
                'range_pool' => 'Range IP pool wajib diisi', 'id_lokasi' => 'Cabang wajib diisi',
            ]);
    }

    public function test_router_must_belong_to_selected_branch_or_be_shared(): void
    {
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Tasik']);
        $this->router->update(['id_lokasi' => $lain->id]);

        $this->postJson('/paket', $this->data(['buat_profile' => false, 'router_id' => $this->router->id, 'profile_mikrotik' => '20Mbps']))
            ->assertStatus(422)->assertJsonValidationErrors(['router_id' => 'Router tidak ada di cabang terpilih']);
        $this->assertSame(0, Paket::count());
    }

    public function test_show_in_all_branches_option_controls_branch_restriction(): void
    {
        $this->postJson('/paket', $this->data(['nama_paket' => 'Khusus Garut']))->assertOk();
        $this->postJson('/paket', $this->data(['nama_paket' => 'Semua Cabang', 'semua_cabang' => true]))->assertOk();

        $this->assertTrue(Paket::firstWhere('nama_paket', 'Khusus Garut')->filter_lokasi);
        $semua = Paket::firstWhere('nama_paket', 'Semua Cabang');
        $this->assertSame([false, $this->lokasi->id], [$semua->filter_lokasi, $semua->id_lokasi]);
    }

    public function test_branch_user_cannot_show_package_in_all_branches(): void
    {
        $this->actingAs(User::factory()->peran('Admin')->create(['id_lokasi' => $this->lokasi->id]));

        $this->postJson('/paket', $this->data(['semua_cabang' => true]))
            ->assertStatus(422)->assertJsonValidationErrors(['semua_cabang' => 'Tampilkan di semua cabang tidak diizinkan']);
    }

    public function test_new_profile_without_router_is_created_only_on_routers_of_the_branch(): void
    {
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Tasik']);
        $this->router->update(['id_lokasi' => $this->lokasi->id]);
        Router::create([
            'nama_router' => 'RB Tasik', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.20', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR', 'id_lokasi' => $lain->id,
        ]);

        $this->postJson('/paket', $this->data())
            ->assertOk()->assertJsonPath('message', 'Paket Ultra 30 Mbps ditambahkan. CCR Pusat: profile UP-30M dibuat');
    }

    public function test_static_package_keeps_rate_limit_for_customer_queues_without_creating_a_profile(): void
    {
        $this->postJson('/paket', $this->data(['nama_paket' => 'Static 10M', 'jns_koneksi' => 'static', 'profile_mikrotik' => 'abaikan', 'buat_profile' => true, 'rate_limit' => '10M/10M']))
            ->assertOk()->assertJsonPath('message', 'Paket Static 10M ditambahkan');

        $paket = Paket::firstWhere('nama_paket', 'Static 10M');
        $this->assertSame([null, false, '10M/10M'], [$paket->profile_mikrotik, $paket->buat_profile, $paket->rate_limit]);
        $this->assertSame([], $this->gw->savedProfiles);
        $paket->update(['rate_limit' => null]);
        $this->postJson("/paket/{$paket->id}/sinkron")
            ->assertStatus(422)->assertJsonPath('message', 'Paket Static 10M (Static) belum diisi rate limit, tidak ada yang disinkronkan');
    }

    public function test_changing_static_package_rate_limit_updates_queues_of_active_customers_and_sync_button_repeats_it(): void
    {
        $static = ['nama_paket' => 'Static 10M', 'jns_koneksi' => 'static', 'rate_limit' => '10M/10M'];
        $this->postJson('/paket', $this->data($static))->assertOk();
        $paket = Paket::firstWhere('nama_paket', 'Static 10M');
        $pelanggan = fn (string $id, string $nama, ?string $ip, string $status = 'aktif') => Pelanggan::create([
            'id_pelanggan' => $id, 'nama' => $nama, 'id_lokasi' => $this->lokasi->id, 'router_id' => $this->router->id,
            'jns_koneksi' => 'static', 'ip_address' => $ip, 'id_paket' => $paket->id, 'status' => $status,
        ]);
        $pelanggan('RM001', 'Agus', '192.168.10.2');
        $budi = $pelanggan('RM002', 'Budi', null);
        $pelanggan('RM003', 'Cici', '192.168.10.4', 'berhenti');
        $this->gw->queues = [['id' => '*7', 'name' => 'AGUS MANUAL', 'target' => '192.168.10.2/32', 'max-limit' => '10M/10M']];

        $this->putJson("/paket/{$paket->id}", $this->data([...$static, 'rate_limit' => '15M/15M']))
            ->assertOk()->assertJsonPath('type', 'warning')
            ->assertJsonPath('message', 'Paket Static 10M diperbarui. CCR Pusat: queue 1 pelanggan diperbarui, gagal: Budi (IP address Budi belum diisi, edit data pelanggan dulu)');
        $this->assertSame([['*7', 'RM001 Agus', '15M/15M']], array_map(fn ($q) => [$q['id'], $q['name'], $q['max-limit']], $this->gw->queues));

        $budi->update(['ip_address' => '192.168.10.3']);
        $this->postJson("/paket/{$paket->id}/sinkron")->assertOk()->assertJsonPath('message', 'CCR Pusat: queue 2 pelanggan diperbarui');
        // Queue Agus diperbarui di tempat; queue baru Budi ditaruh paling atas
        $this->assertSame(['RM002 Budi', 'RM001 Agus'], array_column($this->gw->queues, 'name'));
    }

    public function test_package_list_can_be_filtered_by_connection_type(): void
    {
        Paket::create($this->data(['nama_paket' => 'PPPoE 30M']));
        Paket::create($this->data(['nama_paket' => 'Binding 30M', 'jns_koneksi' => 'ip_binding', 'profile_mikrotik' => null]));

        $this->get('/kelola-data/paket?jns_koneksi=ip_binding')->assertInertia(fn ($page) => $page
            ->where('filters.jns_koneksi', 'ip_binding')
            ->has('rows', 1)
            ->where('rows.0.nama_paket', 'Binding 30M'));
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
            'id_lokasi' => $this->lokasi->id,
            ...$attrs,
        ];
    }
}
