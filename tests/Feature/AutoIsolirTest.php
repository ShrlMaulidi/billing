<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IsolirLog;
use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Pengaturan;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\RouterOsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeConnector;
use Tests\Fakes\FakeGateway;
use Tests\TestCase;

class AutoIsolirTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gw;

    private FakeConnector $connector;

    private Router $router;

    private Lokasi $lokasi;

    private Paket $paket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gw = new FakeGateway;
        $this->connector = new FakeConnector($this->gw);
        $this->app->instance(MikrotikConnector::class, $this->connector);
        $this->router = Router::create([
            'nama_router' => 'CCR Pusat', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.10', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR',
        ]);
        $this->lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => '20Mbps']);
    }

    public function test_h_plus_one_isolates_the_day_after_due_date_at_the_configured_hour_once(): void
    {
        $budi = $this->pelanggan('budi');
        $this->atur(aktif: true, hari: 1, jam: '10:00');

        // 11 Nov 2026 09:59 WIB (02:59 UTC): jatuh tempo tanggal 10, tetapi jam isolir belum tiba
        $this->travelTo('2026-11-11 02:59:00');
        $this->artisan('isolir:otomatis')->assertSuccessful();
        $this->assertSame('on', $budi->fresh()->status_langganan);

        $this->travelTo('2026-11-11 03:00:00');
        $this->artisan('isolir:otomatis')->expectsOutputToContain('1 pelanggan diisolir')->assertSuccessful();
        $this->assertSame('off', $budi->fresh()->status_langganan);
        $this->assertSame('ISOLIR', $this->gw->secrets['budi']['profile']);
        $this->assertDatabaseHas('audit_log', ['modul' => 'Pelanggan', 'aksi' => 'isolir', 'subjek_id' => $budi->id, 'user_nama' => 'Sistem (auto isolir)']);
        $this->assertSame(['diisolir' => 1, 'gagal' => 0], collect(json_decode(Pengaturan::find('auto_isolir_hasil')->nilai, true))->only(['diisolir', 'gagal'])->all());

        // Staf menyalakan lagi (janji bayar): jalan berikutnya di hari yang sama & besok tidak mengisolir ulang
        $this->gw->secrets['budi']['profile'] = '20Mbps';
        $budi->refresh()->update(['status_langganan' => 'on']);
        IsolirLog::create(['pelanggan_id' => $budi->id, 'router_id' => $this->router->id, 'username' => 'budi', 'aksi' => 'aktifkan', 'berhasil' => true]);
        $this->artisan('isolir:otomatis')->assertSuccessful();
        $this->travelTo('2026-11-12 03:00:00');
        $this->artisan('isolir:otomatis')->assertSuccessful();
        $this->assertSame('on', $budi->fresh()->status_langganan);
    }

    public function test_due_day_option_isolates_on_the_due_date_itself(): void
    {
        $budi = $this->pelanggan('budi');
        $this->atur(aktif: true, hari: 0, jam: '08:00');
        $this->travelTo('2026-11-10 01:00:00');

        $this->artisan('isolir:otomatis')->assertSuccessful();

        $this->assertSame('off', $budi->fresh()->status_langganan);
    }

    public function test_only_overdue_unpaid_customers_with_router_are_targeted(): void
    {
        $this->travelTo('2026-11-11 03:00:00');
        $budi = $this->pelanggan('budi');
        $this->pelanggan('lunas', ['tgl_jatuh_tempo' => 10]);
        Pembayaran::create(['pelanggan_id' => Pelanggan::firstWhere('nama', 'Lunas')->id, 'id_lokasi' => $this->lokasi->id, 'periode' => '2026-11-01', 'nama_pelanggan' => 'Lunas',
            'nama_paket' => 'Home', 'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => '2026-11-05 09:00:00']);
        $this->pelanggan('belum_tempo', ['tgl_jatuh_tempo' => 20]);
        $this->pelanggan('tanpa_tempo', ['tgl_jatuh_tempo' => null]);
        $this->pelanggan('sudah_off', ['status_langganan' => 'off']);
        $this->pelanggan('tanpa_router', ['router_id' => null]);
        $this->pelanggan('gratis', ['id_paket' => Paket::create(['nama_paket' => 'Free', 'harga' => 0])->id]);
        // Daftar 11 Nov, setelah jatuh tempo tanggal 10: tagihan November tidak dihitung lewat jatuh tempo
        $this->pelanggan('baru', ['tgl_registrasi' => '2026-11-11']);
        $this->atur(aktif: false, hari: 1, jam: '10:00');
        $this->actingAs(User::factory()->peran('Super Admin')->create());

        $this->get('/kelola-data/auto-isolir')->assertOk()->assertInertia(fn ($page) => $page->component('KelolaData/AutoIsolir')
            ->where('berikutnya', null)->where('penjadwalAktif', false)
            ->has('pratinjau', 1)->where('pratinjau.0.id', $budi->id)->where('pratinjau.0.jatuh_tempo', '2026-11-10'));
    }

    public function test_only_the_current_month_bill_counts_and_older_arrears_are_ignored(): void
    {
        $this->travelTo('2026-11-11 03:00:00');
        // Menunggak sejak Januari tetapi tagihan November sudah dibayar: tidak diisolir
        $lama = $this->pelanggan('lama', ['tgl_registrasi' => '2026-01-01']);
        Pembayaran::create(['pelanggan_id' => $lama->id, 'id_lokasi' => $this->lokasi->id, 'periode' => '2026-11-01', 'nama_pelanggan' => 'Lama',
            'nama_paket' => 'Home', 'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => '2026-11-05 09:00:00']);
        $budi = $this->pelanggan('budi', ['tgl_registrasi' => '2026-01-01']);
        $this->atur(aktif: false, hari: 1, jam: '10:00');
        $this->actingAs(User::factory()->peran('Super Admin')->create());

        $this->get('/kelola-data/auto-isolir')->assertInertia(fn ($page) => $page->has('pratinjau', 1)
            ->where('pratinjau.0.id', $budi->id)->where('pratinjau.0.periode', '2026-11-01'));

        // Jatuh tempo lama tanggal 31 Oktober + H+1 = 1 November: tagihan Oktober masih dihitung di awal bulan ini
        $budi->update(['status_langganan' => 'off']);
        $akhir = $this->pelanggan('akhir', ['tgl_registrasi' => '2026-01-01', 'tgl_jatuh_tempo' => 31]);
        $this->travelTo('2026-11-01 03:00:00');
        $this->get('/kelola-data/auto-isolir')->assertInertia(fn ($page) => $page->has('pratinjau', 1)
            ->where('pratinjau.0.id', $akhir->id)->where('pratinjau.0.periode', '2026-10-01')->where('pratinjau.0.jatuh_tempo', '2026-10-31'));
    }

    public function test_paying_the_current_bill_turns_isolated_internet_back_on(): void
    {
        // Tanpa withoutDefer: menyalakan internet berjalan setelah respons terkirim, seperti di server
        $this->travelTo('2026-11-12 03:00:00');
        $budi = $this->terisolir('budi');
        $this->actingAs(User::factory()->peran('Admin')->create());

        $this->postJson("/pelanggan/{$budi->id}/bayar", ['bulan' => 11, 'tahun' => 2026, 'metode' => 'cash'])
            ->assertOk()->assertJsonPath('message', fn (string $pesan) => str_ends_with($pesan, 'Internet diaktifkan kembali otomatis.'));

        $this->assertSame('on', $budi->fresh()->status_langganan);
        $this->assertSame('20Mbps', $this->gw->secrets['budi']['profile']);
        $this->assertContains('budi', $this->gw->kicked);
        $this->assertDatabaseHas('audit_log', ['modul' => 'Pelanggan', 'aksi' => 'aktifkan', 'subjek_id' => $budi->id]);
    }

    public function test_payment_gateway_payment_turns_internet_back_on_but_an_older_month_does_not(): void
    {
        $this->withoutDefer();
        $this->travelTo('2026-11-12 03:00:00');
        $budi = $this->terisolir('budi', ['tgl_registrasi' => '2026-01-01']);
        $budi->load('paket');
        $bayar = fn (string $periode, string $metode) => Pembayaran::catatUntuk($budi, ['periode' => $periode, 'harga' => 200000, 'metode' => $metode, 'rekening_id' => null,
            'diskon_tipe' => null, 'diskon_nilai' => 0, 'diskon' => 0, 'jml_bayar' => 200000], null);

        // Bayar tunggakan Oktober saja: tagihan November masih lewat jatuh tempo, tetap terisolir
        $bayar('2026-10-01', 'cash');
        $this->assertSame('off', $budi->fresh()->status_langganan);

        $bayar('2026-11-01', 'midtrans');
        $this->assertSame('on', $budi->fresh()->status_langganan);
        $this->assertDatabaseHas('audit_log', ['aksi' => 'aktifkan', 'subjek_id' => $budi->id, 'user_nama' => 'Sistem (aktif otomatis setelah bayar)']);
    }

    public function test_payment_is_kept_when_the_router_cannot_be_reached_to_turn_internet_back_on(): void
    {
        $this->withoutDefer();
        $this->travelTo('2026-11-12 03:00:00');
        $budi = $this->terisolir('budi');
        $this->connector->gagal = new RouterOsException('Router tidak terjangkau', 'koneksi');
        $this->actingAs(User::factory()->peran('Admin')->create());

        $this->postJson("/pelanggan/{$budi->id}/bayar", ['bulan' => 11, 'tahun' => 2026, 'metode' => 'cash'])->assertOk();

        $this->assertDatabaseHas('pembayaran', ['pelanggan_id' => $budi->id, 'periode' => '2026-11-01']);
        $this->assertSame('off', $budi->fresh()->status_langganan);
        $this->assertTrue(AuditLog::where('aksi', 'aktifkan')->where('deskripsi', 'like', 'Gagal menyalakan kembali%')->exists());
    }

    public function test_unreachable_router_is_reported_and_its_other_customers_are_skipped(): void
    {
        $this->pelanggan('budi');
        $this->pelanggan('sari');
        $this->connector->gagal = new RouterOsException('Router tidak terjangkau', 'koneksi');
        $this->atur(aktif: true, hari: 1, jam: '10:00');
        $this->travelTo('2026-11-11 03:00:00');

        $this->artisan('isolir:otomatis')->expectsOutputToContain('0 pelanggan diisolir, 2 gagal')->assertSuccessful();

        // Hanya satu percobaan ke router yang mati
        $this->assertSame(1, IsolirLog::where('berhasil', false)->count());
        $this->assertSame('2026-11-11', Pengaturan::find('auto_isolir_terakhir')->nilai);
    }

    public function test_only_permitted_head_office_users_can_change_settings(): void
    {
        $this->actingAs(User::factory()->peran('Admin')->create());
        $this->get('/kelola-data/auto-isolir')->assertForbidden();
        $this->putJson('/kelola-data/auto-isolir', ['aktif' => true, 'hari' => 0, 'jam' => '09:00'])->assertForbidden();

        $this->actingAs(User::factory()->peran('Super Admin')->create());
        $this->putJson('/kelola-data/auto-isolir', ['aktif' => true, 'hari' => 2, 'jam' => '25:00'])->assertStatus(422)->assertJsonValidationErrors(['hari', 'jam']);
        $this->putJson('/kelola-data/auto-isolir', ['aktif' => true, 'hari' => 0, 'jam' => '09:30'])
            ->assertOk()->assertJsonPath('message', 'Auto isolir aktif: Hari H jatuh tempo, jam 09:30 WIB');

        $this->assertSame(['1', '0', '09:30'], [Pengaturan::find('auto_isolir_aktif')->nilai, Pengaturan::find('auto_isolir_hari')->nilai, Pengaturan::find('auto_isolir_jam')->nilai]);
        $this->assertDatabaseHas('audit_log', ['modul' => 'Auto Isolir', 'aksi' => 'update']);
    }

    /** Pelanggan yang sudah diisolir di router (profile ISOLIR, profile asal tersimpan). */
    private function terisolir(string $username, array $override = []): Pelanggan
    {
        $this->gw->secrets[$username]['profile'] = 'ISOLIR';

        return $this->pelanggan($username, ['status_langganan' => 'off', 'profile_asal' => '20Mbps', 'isolir_at' => now(), ...$override]);
    }

    private function atur(bool $aktif, int $hari, string $jam): void
    {
        Pengaturan::simpanSemua(['auto_isolir_aktif' => $aktif ? '1' : '0', 'auto_isolir_hari' => (string) $hari, 'auto_isolir_jam' => $jam]);
    }

    private function pelanggan(string $username, array $override = []): Pelanggan
    {
        return Pelanggan::create([
            'nama' => ucfirst($username), 'username_pppoe' => $username, 'id_lokasi' => $this->lokasi->id, 'id_paket' => $this->paket->id,
            'router_id' => $this->router->id, 'jns_koneksi' => 'pppoe', 'jns_tagihan' => 'prabayar', 'tgl_registrasi' => '2026-11-01',
            'tgl_jatuh_tempo' => 10, 'status' => 'aktif', 'status_langganan' => 'on', ...$override,
        ]);
    }
}
