<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\Tunnel;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\RouterOsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeConnector;
use Tests\Fakes\FakeGateway;
use Tests\TestCase;

class PelangganTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gw;

    private FakeConnector $connector;

    private Router $router;

    private Lokasi $lokasi;

    private Paket $paket;

    private User $sales;

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
        $this->paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '20Mbps']);
        $this->sales = User::factory()->peran('Sales')->create(['name' => 'Agus Sales']);
    }

    public function test_store_creates_customer_with_auto_id_and_unique_username_per_router(): void
    {
        $this->postJson('/pelanggan', $this->data())->assertOk()->assertJsonPath('message', 'Pelanggan Budi Santoso ditambahkan');

        $p = Pelanggan::firstWhere('username_pppoe', 'budi');
        $this->assertSame(sprintf('P%05d', $p->id), $p->id_pelanggan);
        $this->assertSame('on', $p->status_langganan);
        $this->assertSame('aktif', $p->status);
        $this->assertSame(['pascabayar', true, $this->sales->id], [$p->jns_tagihan, $p->ppn, $p->sales_id]);
        $this->assertSame('MSS Garut', $p->toBaris()['cabang']);

        $this->postJson('/pelanggan', $this->data(['nama' => 'Budi Lain']))
            ->assertStatus(422)->assertJsonValidationErrors(['username_pppoe' => 'Username sudah dipakai']);
    }

    public function test_store_rejects_empty_payload_with_required_field_messages(): void
    {
        $this->postJson('/pelanggan', [])->assertStatus(422)->assertJsonValidationErrors([
            'nama' => 'Nama pelanggan wajib diisi', 'tlp' => 'No. telepon wajib diisi', 'alamat' => 'Alamat wajib diisi',
            'jns_tagihan' => 'Jenis tagihan wajib diisi', 'id_lokasi' => 'Cabang wajib diisi', 'router_id' => 'Router wajib diisi',
            'jns_koneksi' => 'Jenis koneksi wajib diisi', 'id_paket' => 'Paket wajib diisi', 'tgl_registrasi' => 'Tanggal registrasi wajib diisi',
            'tgl_jatuh_tempo' => 'Tanggal jatuh tempo wajib diisi', 'ppn' => 'PPN wajib diisi', 'sales_id' => 'Sales wajib diisi',
        ])->assertJsonMissingValidationErrors(['nik', 'id_area', 'id_pelanggan']);

        $this->assertDatabaseCount('pelanggan', 0);
    }

    public function test_store_rejects_router_area_package_and_sales_outside_the_chosen_branch(): void
    {
        $lain = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi']);
        $routerLain = Router::create([...$this->router->only(['mode_koneksi', 'host', 'port_api', 'username', 'profile_isolir']), 'nama_router' => 'RB Cimahi', 'password' => 'x', 'id_lokasi' => $lain->id]);
        $areaLain = Area::create(['nama_area' => 'Cimahi Utara', 'id_lokasi' => $lain->id]);
        $paketLain = Paket::create([...$this->paket->only(['harga', 'jenis', 'jns_koneksi', 'profile_mikrotik']), 'nama_paket' => 'Khusus Cimahi', 'filter_lokasi' => true, 'id_lokasi' => $lain->id]);
        $bukanSales = User::factory()->peran('Admin')->create();

        $this->postJson('/pelanggan', $this->data([
            'router_id' => $routerLain->id, 'id_area' => $areaLain->id, 'id_paket' => $paketLain->id, 'sales_id' => $bukanSales->id, 'nik' => '320512',
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'router_id' => 'Router tidak ditemukan', 'id_area' => 'Area tidak ditemukan', 'id_paket' => 'Paket tidak ditemukan',
            'sales_id' => 'Sales tidak valid', 'nik' => 'NIK harus 16 digit angka',
        ]);

        $this->assertDatabaseCount('pelanggan', 0);
    }

    public function test_new_pppoe_account_is_created_on_router_with_package_profile(): void
    {
        $this->postJson('/pelanggan', $this->data(['mode_akun' => 'baru', 'username_pppoe' => 'budi.baru', 'password_akun' => 'rahasia1']))
            ->assertOk()->assertJsonPath('message', "Pelanggan Budi Santoso ditambahkan. PPP Secret 'budi.baru' dibuat di router CCR Pusat");

        $p = Pelanggan::firstWhere('username_pppoe', 'budi.baru');
        $this->assertSame(['comment' => "{$p->id_pelanggan} Budi Santoso", 'name' => 'budi.baru', 'password' => 'rahasia1', 'profile' => '20Mbps', 'service' => 'pppoe', 'remote-address' => '10.12.10.5'],
            array_intersect_key($this->gw->secrets['budi.baru'], array_flip(['name', 'password', 'profile', 'comment', 'service', 'remote-address'])));
        $this->assertArrayNotHasKey('password_akun', $p->getAttributes());
    }

    public function test_new_hotspot_user_and_ip_binding_are_created_on_router(): void
    {
        $hotspot = Paket::create([...$this->paket->only(['harga', 'jenis']), 'nama_paket' => 'Hotspot 5M', 'jns_koneksi' => 'hotspot', 'profile_mikrotik' => 'HS-5M']);

        $this->postJson('/pelanggan', $this->data(['jns_koneksi' => 'hotspot', 'mode_akun' => 'baru', 'username_pppoe' => 'warung', 'password_akun' => 'abc', 'id_paket' => $hotspot->id]))
            ->assertOk()->assertJsonPath('message', "Pelanggan Budi Santoso ditambahkan. Hotspot User 'warung' dibuat di router CCR Pusat");
        $this->postJson('/pelanggan', $this->data(['nama' => 'Sari', 'jns_koneksi' => 'ip_binding', 'mode_akun' => 'baru', 'mac_address' => 'aa:bb:cc:dd:ee:02', 'ip_address' => '192.168.10.7']))
            ->assertOk()->assertJsonPath('message', "Pelanggan Sari ditambahkan. IP Binding 'AA:BB:CC:DD:EE:02' dibuat di router CCR Pusat");

        $this->assertSame(['name' => 'warung', 'password' => 'abc', 'profile' => 'HS-5M'], array_intersect_key(end($this->gw->akun['hotspot']), array_flip(['name', 'password', 'profile'])));
        $this->assertSame(['mac-address' => 'AA:BB:CC:DD:EE:02', 'address' => '192.168.10.7', 'type' => 'bypassed'], array_intersect_key(end($this->gw->akun['ip_binding']), array_flip(['mac-address', 'address', 'type'])));
        $this->assertDatabaseHas('pelanggan', ['nama' => 'Sari', 'jns_koneksi' => 'ip_binding', 'mac_address' => 'AA:BB:CC:DD:EE:02', 'username_pppoe' => null]);
    }

    public function test_sync_mode_and_static_connection_do_not_change_router(): void
    {
        $secrets = $this->gw->secrets;

        $this->postJson('/pelanggan', $this->data())->assertOk()->assertJsonPath('message', 'Pelanggan Budi Santoso ditambahkan');
        $this->postJson('/pelanggan', $this->data(['nama' => 'Kantor Desa', 'jns_koneksi' => 'static', 'mode_akun' => null, 'username_pppoe' => 'abaikan', 'ip_address' => '10.20.0.5']))
            ->assertOk()->assertJsonPath('message', 'Pelanggan Kantor Desa ditambahkan');

        $this->assertSame($secrets, $this->gw->secrets);
        $this->assertDatabaseHas('pelanggan', ['nama' => 'Kantor Desa', 'jns_koneksi' => 'static', 'ip_address' => '10.20.0.5', 'username_pppoe' => null]);
    }

    public function test_new_account_failure_keeps_customer_and_returns_warning(): void
    {
        $this->postJson('/pelanggan', $this->data(['mode_akun' => 'baru', 'password_akun' => 'x']))
            ->assertOk()->assertJsonPath('type', 'warning')
            ->assertJsonPath('message', "Pelanggan Budi Santoso ditambahkan, tetapi akun gagal dibuat di router: PPP Secret 'budi' sudah ada di router CCR Pusat, pilih Sinkronisasi");
        $this->connector->gagal = RouterOsException::koneksi('Tidak dapat terhubung ke 203.0.113.10:8728');
        $this->postJson('/pelanggan', $this->data(['nama' => 'Tono', 'username_pppoe' => 'tono.baru', 'ip_address' => '10.12.10.6', 'mode_akun' => 'baru', 'password_akun' => 'x']))
            ->assertOk()->assertJsonPath('type', 'warning')
            ->assertJsonPath('message', 'Pelanggan Tono ditambahkan, tetapi akun gagal dibuat di router: Tidak dapat terhubung ke 203.0.113.10:8728');

        $this->assertDatabaseCount('pelanggan', 2);
    }

    public function test_router_accounts_endpoint_lists_accounts_for_sync(): void
    {
        $this->getJson("/pelanggan/akun-router/{$this->router->id}?jenis=hotspot")
            ->assertOk()->assertExactJson(['data' => [['name' => 'voucher1', 'profile' => 'HS-1H', 'address' => null, 'comment' => 'Warung']]]);
        $this->getJson("/pelanggan/akun-router/{$this->router->id}?jenis=static")->assertStatus(422)->assertJsonValidationErrors('jenis');

        $this->connector->gagal = RouterOsException::koneksi('Tidak dapat terhubung ke 203.0.113.10:8728');
        $this->getJson("/pelanggan/akun-router/{$this->router->id}?jenis=pppoe")
            ->assertStatus(422)->assertJsonPath('message', 'CCR Pusat: Tidak dapat terhubung ke 203.0.113.10:8728');
    }

    public function test_router_actions_are_rate_limited_per_user(): void
    {
        foreach (range(1, 30) as $_) {
            $this->getJson("/pelanggan/akun-router/{$this->router->id}?jenis=pppoe")->assertOk();
        }

        $this->getJson("/pelanggan/akun-router/{$this->router->id}?jenis=pppoe")
            ->assertTooManyRequests()->assertJsonPath('message', 'Terlalu banyak permintaan ke router, coba lagi sebentar lagi');
    }

    public function test_on_off_is_rejected_for_non_pppoe_connection(): void
    {
        $p = $this->pelanggan('warung', ['jns_koneksi' => 'hotspot']);

        $this->postJson("/pelanggan/{$p->id}/isolir")
            ->assertStatus(422)->assertJsonPath('message', 'On/Off internet untuk koneksi Hotspot belum tersedia, baru PPPoE');

        $this->assertSame('on', $p->fresh()->status_langganan);
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

    public function test_changing_package_moves_router_secret_to_new_profile_and_kicks_session(): void
    {
        $p = $this->pelangganDariForm();
        $paket10 = Paket::create(['nama_paket' => 'Home 10 Mbps', 'harga' => 150000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '10Mbps']);

        $this->putJson("/pelanggan/{$p->id}", $this->data(['id_paket' => $paket10->id, 'status' => 'aktif']))
            ->assertOk()->assertJsonPath('message', 'Data Budi Santoso diperbarui, profile router 20Mbps → 10Mbps, sesi diputus agar login ulang');

        $this->assertSame('10Mbps', $this->gw->secrets['budi']['profile']);
        $this->assertSame(['budi'], $this->gw->kicked);
        $this->assertDatabaseHas('isolir_logs', ['pelanggan_id' => $p->id, 'aksi' => 'ganti_paket', 'profile_dari' => '20Mbps', 'profile_ke' => '10Mbps']);
    }

    public function test_pppoe_remote_address_is_required_unique_per_router_and_updated_on_router(): void
    {
        $this->postJson('/pelanggan', $this->data(['ip_address' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['ip_address' => 'IP address wajib diisi']);

        $p = $this->pelangganDariForm();
        $this->postJson('/pelanggan', $this->data(['nama' => 'Sari', 'username_pppoe' => 'sari']))
            ->assertStatus(422)->assertJsonValidationErrors(['ip_address' => 'IP address sudah dipakai']);

        $this->putJson("/pelanggan/{$p->id}", $this->data(['ip_address' => '10.12.10.9', 'status' => 'aktif']))
            ->assertOk()->assertJsonPath('message', 'Data Budi Santoso diperbarui, remote address 10.12.10.9, sesi diputus agar login ulang');

        $this->assertSame('10.12.10.9', $this->gw->secrets['budi']['remote_address']);
        $this->assertSame('20Mbps', $this->gw->secrets['budi']['profile']);
    }

    public function test_ip_already_used_on_router_but_not_in_billing_is_rejected(): void
    {
        // 10.12.10.3 dipakai secret 'tono' yang belum tercatat di billing, 10.12.10.2 dipakai sesi aktif 'budi'
        $this->postJson('/pelanggan', $this->data(['nama' => 'Sari', 'username_pppoe' => 'sari', 'ip_address' => '10.12.10.3']))
            ->assertStatus(422)->assertJsonValidationErrors(['ip_address' => "IP sudah dipakai akun PPPoE 'tono' di router"]);
        $this->postJson('/pelanggan', $this->data(['nama' => 'Sari', 'username_pppoe' => 'sari', 'ip_address' => '10.12.10.2']))
            ->assertStatus(422)->assertJsonValidationErrors(['ip_address' => "IP sudah dipakai akun PPPoE 'budi' di router"]);

        // Sinkronisasi akun 'tono' sendiri memakai IP-nya di router: bukan bentrok
        $this->postJson('/pelanggan', $this->data(['nama' => 'Tono', 'username_pppoe' => 'tono', 'ip_address' => '10.12.10.3']))->assertOk();
    }

    public function test_ip_router_endpoint_lists_pool_free_ips_excluding_router_and_billing_usage(): void
    {
        $this->postJson('/pelanggan', $this->data(['username_pppoe' => 'budi.baru', 'ip_address' => '10.12.10.5']))->assertOk();

        $this->getJson("/pelanggan/ip-router/{$this->router->id}")->assertOk()
            ->assertJsonPath('pools.0', ['name' => 'pool-pppoe', 'ranges' => '10.12.10.2-10.12.10.6', 'kosong' => ['10.12.10.4', '10.12.10.6'], 'jumlah_kosong' => 2])
            ->assertJsonPath('terpakai', ['10.12.10.3' => 'tono', '10.12.10.2' => 'budi']);
    }

    public function test_changing_package_of_isolated_customer_keeps_isolir_and_updates_restore_profile(): void
    {
        $p = $this->pelangganDariForm();
        $this->postJson("/pelanggan/{$p->id}/isolir")->assertOk();
        $paket10 = Paket::create(['nama_paket' => 'Home 10 Mbps', 'harga' => 150000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '10Mbps']);

        $this->putJson("/pelanggan/{$p->id}", $this->data(['id_paket' => $paket10->id, 'status' => 'aktif']))->assertOk();

        $this->assertSame('ISOLIR', $this->gw->secrets['budi']['profile']);
        $this->assertSame('10Mbps', $p->fresh()->profile_asal);
    }

    public function test_changing_package_reports_warning_when_profile_missing_on_router(): void
    {
        $p = $this->pelangganDariForm();
        $paketBaru = Paket::create(['nama_paket' => 'Home 50 Mbps', 'harga' => 300000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '50Mbps']);

        $this->putJson("/pelanggan/{$p->id}", $this->data(['id_paket' => $paketBaru->id, 'status' => 'aktif']))
            ->assertOk()->assertJsonPath('type', 'warning');

        $this->assertSame($paketBaru->id, $p->fresh()->id_paket);
        $this->assertSame('20Mbps', $this->gw->secrets['budi']['profile']);
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

    private function data(array $override = []): array
    {
        return [
            'nama' => 'Budi Santoso', 'tlp' => '081234567890', 'nik' => '3205123456789012', 'alamat' => 'Jl. Cikuray 1', 'jns_tagihan' => 'pascabayar',
            'id_lokasi' => $this->lokasi->id, 'router_id' => $this->router->id, 'jns_koneksi' => 'pppoe', 'mode_akun' => 'sinkron', 'username_pppoe' => 'budi', 'ip_address' => '10.12.10.5',
            'id_paket' => $this->paket->id, 'tgl_registrasi' => '2026-10-30', 'tgl_jatuh_tempo' => 10, 'ppn' => true, 'sales_id' => $this->sales->id,
            ...$override,
        ];
    }

    /** Pelanggan PPPoE 'budi' yang disimpan lewat form (cabang, sales, paket 20Mbps lengkap). */
    private function pelangganDariForm(): Pelanggan
    {
        $this->postJson('/pelanggan', $this->data())->assertOk();

        return Pelanggan::where('username_pppoe', 'budi')->firstOrFail();
    }

    private function pelanggan(string $username, array $attrs = []): Pelanggan
    {
        return Pelanggan::create([
            'nama' => ucfirst($username), 'id_lokasi' => 1, 'router_id' => $this->router->id, 'username_pppoe' => $username, ...$attrs,
        ]);
    }
}
