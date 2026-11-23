<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PesanWa;
use App\Models\Router;
use App\Models\TemplatePesan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTagihanTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI = 'kunci-rahasia-gateway-untuk-pengujian-0123456789';

    private Lokasi $garut;

    private Lokasi $tasik;

    private Router $router;

    private Paket $paket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 09:00:00');
        config(['services.wa_gateway.key' => self::KUNCI, 'services.wa_gateway.url' => 'http://127.0.0.1:3100']);
        $this->garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->tasik = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Tasik']);
        $this->router = Router::create([
            'nama_router' => 'CCR Pusat', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.10', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR',
        ]);
        $this->paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'static']);
    }

    public function test_branch_user_sees_and_connects_only_its_own_branch_whatsapp(): void
    {
        $this->gateway();
        $mitra = User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]);

        $this->actingAs($mitra)->get('/kelola-data/notifikasi')->assertOk()->assertInertia(fn ($page) => $page->component('KelolaData/Notifikasi')
            ->where('gateway', 'siap')
            ->has('cabang', 1)
            ->where('cabang.0.nama', 'MSS Garut')
            ->where('cabang.0.wa.status', 'connected')
            ->where('cabang.0.wa.nomor', '6281300000001'));

        $this->actingAs($mitra)->postJson("/kelola-data/notifikasi/{$this->tasik->id}/whatsapp")->assertNotFound();
        $this->actingAs($mitra)->postJson("/kelola-data/notifikasi/{$this->garut->id}/whatsapp")->assertOk()->assertJsonPath('status', 'qr')->assertJsonPath('qr', 'teks-qr');

        Http::assertSent(fn (Request $r) => $r->url() === "http://127.0.0.1:3100/sessions/cabang-{$this->garut->id}/start" && $r->header('X-Api-Key') === [self::KUNCI]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), "cabang-{$this->tasik->id}"));
    }

    public function test_notification_page_reports_unconfigured_or_stopped_gateway(): void
    {
        config(['services.wa_gateway.key' => null]);
        $this->actingAs(User::factory()->create())->get('/kelola-data/notifikasi')->assertInertia(fn ($page) => $page->where('gateway', 'belum_dikonfigurasi')->where('cabang.0.wa', null));

        config(['services.wa_gateway.key' => self::KUNCI]);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $this->actingAs(User::factory()->create())->get('/kelola-data/notifikasi')->assertInertia(fn ($page) => $page->where('gateway', 'offline'));
        $this->postJson("/kelola-data/notifikasi/{$this->garut->id}/whatsapp")->assertStatus(503)
            ->assertJsonPath('message', 'Layanan WhatsApp di server sedang tidak berjalan. Hubungi admin pusat.');
    }

    public function test_billing_template_is_set_per_branch(): void
    {
        $this->gateway();
        $mitra = User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]);

        $this->actingAs($mitra)->putJson("/kelola-data/notifikasi/{$this->garut->id}/template", ['isi' => "Yth. {nama},\r\ntagihan {tagihan}."])->assertOk();
        $this->assertSame("Yth. {nama},\ntagihan {tagihan}.", TemplatePesan::isiUntuk($this->garut->id));
        $this->assertSame(TemplatePesan::BAWAAN, TemplatePesan::isiUntuk($this->tasik->id));

        $this->actingAs($mitra)->putJson("/kelola-data/notifikasi/{$this->tasik->id}/template", ['isi' => 'Bukan cabang saya'])->assertNotFound();
        $this->actingAs($mitra)->putJson("/kelola-data/notifikasi/{$this->garut->id}/template", ['isi' => ''])->assertUnprocessable()->assertJsonValidationErrors('isi');
        $this->actingAs($mitra)->putJson("/kelola-data/notifikasi/{$this->garut->id}/template", ['isi' => str_repeat('a', TemplatePesan::MAKS_ISI + 1)])->assertUnprocessable();

        $this->actingAs($mitra)->deleteJson("/kelola-data/notifikasi/{$this->garut->id}/template")->assertOk();
        $this->assertSame(TemplatePesan::BAWAAN, TemplatePesan::isiUntuk($this->garut->id));
    }

    public function test_bill_is_sent_from_customers_branch_whatsapp_using_branch_template(): void
    {
        $this->gateway();
        TemplatePesan::create(['id_lokasi' => $this->garut->id, 'isi' => 'Halo {nama} ({id_pelanggan}), tagihan {paket} {periode} {tagihan} jatuh tempo {jatuh_tempo}. {link} — {cabang}']);
        $budi = $this->pelanggan('Budi', '0813-0000-9901', $this->garut);
        $admin = User::factory()->peran('Admin')->create(['id_lokasi' => $this->garut->id]);

        $this->actingAs($admin)->postJson("/pelanggan/{$budi->id}/tagihan-wa", ['bulan' => 11, 'tahun' => 2026])->assertOk()
            ->assertJsonPath('message', 'Tagihan terkirim ke WhatsApp Budi (081300009901)');

        $teksDiharapkan = 'Halo Budi (MSS-0001), tagihan Home 20 Mbps November 2026 Rp 200.000 jatuh tempo 10 November 2026.';
        Http::assertSent(fn (Request $r) => $r->url() === "http://127.0.0.1:3100/sessions/cabang-{$this->garut->id}/send"
            && $r['to'] === '6281300009901'
            && str_starts_with($r['text'], $teksDiharapkan)
            && str_contains($r['text'], "/cek-tagihan/kartu/{$budi->id}?signature=")
            && str_ends_with($r['text'], '— MSS Garut'));
        $pesan = PesanWa::sole();
        $this->assertSame(['terkirim', 'WAID1', '2026-11-01', $admin->id], [$pesan->status, $pesan->wa_id, $pesan->periode->toDateString(), $pesan->user_id]);

        $this->actingAs($admin)->get('/pelanggan/belum-lunas?bulan=11&tahun=2026')->assertInertia(fn ($page) => $page
            ->where('rows.0.wa_terkirim', fn ($waktu) => str_starts_with((string) $waktu, '2026-11-15')));
        $this->actingAs($admin)->getJson("/pelanggan/{$budi->id}/tagihan-wa?bulan=11&tahun=2026")->assertOk()
            ->assertJsonPath('wa_terhubung', true)
            ->assertJsonPath('nomor', '081300009901')
            ->assertJsonPath('terakhir', fn ($waktu) => str_starts_with((string) $waktu, '2026-11-15'));
    }

    public function test_off_and_prorate_pages_remind_the_right_bill(): void
    {
        $this->gateway();
        // Off: September sudah dibayar, jadi yang diingatkan tunggakan terlama Oktober
        $andi = $this->pelanggan('Andi', '081300009902', $this->garut, ['tgl_registrasi' => '2026-09-01', 'status_langganan' => 'off']);
        Pembayaran::create([
            'pelanggan_id' => $andi->id, 'id_lokasi' => $this->garut->id, 'periode' => '2026-09-01', 'jns_tagihan' => 'prabayar', 'nama_pelanggan' => 'Andi',
            'nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => '2026-09-05 10:00:00',
        ]);
        // Prorate: registrasi 10 November, tagihan prorate muncul di bulan November
        $citra = $this->pelanggan('Citra', '081300009903', $this->garut, ['tgl_registrasi' => '2026-11-10', 'skema_tagihan' => 'prorate']);
        $this->actingAs(User::factory()->create());

        $this->get('/pelanggan/off')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.bulan_wa', '2026-10-01')->where('rows.0.wa_terkirim', null));
        $this->get('/pelanggan/prorate')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama', 'Citra')->where('rows.0.bulan_wa', '2026-11-01'));

        $this->postJson("/pelanggan/{$andi->id}/tagihan-wa", ['bulan' => 10, 'tahun' => 2026])->assertOk();
        $this->postJson("/pelanggan/{$citra->id}/tagihan-wa", ['bulan' => 11, 'tahun' => 2026])->assertOk();

        Http::assertSent(fn (Request $r) => $r['to'] === '6281300009902' && str_contains($r['text'], 'Oktober 2026'));
        // Prorate 10–30 November dari Rp 200.000 = Rp 140.000 (dibulatkan ke atas per seribu)
        Http::assertSent(fn (Request $r) => $r['to'] === '6281300009903' && str_contains($r['text'], 'Rp 140.000'));
        $this->get('/pelanggan/off')->assertInertia(fn ($page) => $page->where('rows.0.wa_terkirim', fn ($waktu) => str_starts_with((string) $waktu, '2026-11-15')));
    }

    public function test_failed_send_is_logged_with_reason(): void
    {
        $this->gateway(kirim: Http::response(['message' => 'Nomor 6281300009901 tidak terdaftar di WhatsApp'], 422));
        $budi = $this->pelanggan('Budi', '081300009901', $this->garut);

        $this->actingAs(User::factory()->create())->postJson("/pelanggan/{$budi->id}/tagihan-wa", ['bulan' => 11, 'tahun' => 2026])
            ->assertStatus(422)->assertJsonPath('message', 'Nomor 6281300009901 tidak terdaftar di WhatsApp');

        $pesan = PesanWa::sole();
        $this->assertSame(['gagal', 'Nomor 6281300009901 tidak terdaftar di WhatsApp'], [$pesan->status, $pesan->keterangan]);
    }

    public function test_bill_is_refused_for_other_branch_paid_period_invalid_phone_or_missing_permission(): void
    {
        $this->gateway();
        $budi = $this->pelanggan('Budi', '081300009901', $this->garut);
        $kirim = fn (User $user, Pelanggan $p) => $this->actingAs($user)->postJson("/pelanggan/{$p->id}/tagihan-wa", ['bulan' => 11, 'tahun' => 2026]);

        $kirim(User::factory()->peran('Admin')->create(['id_lokasi' => $this->tasik->id]), $budi)->assertNotFound();
        $kirim(User::factory()->peran('Teknisi')->create(['id_lokasi' => $this->garut->id]), $budi)->assertForbidden();

        $tanpaNomor = $this->pelanggan('Tanpa Nomor', '12345', $this->garut);
        $kirim(User::factory()->create(), $tanpaNomor)->assertStatus(422)->assertJsonPath('message', 'Nomor HP pelanggan kosong atau tidak valid. Perbaiki di Data Pelanggan.');

        Pembayaran::create([
            'pelanggan_id' => $budi->id, 'id_lokasi' => $this->garut->id, 'periode' => '2026-11-01', 'jns_tagihan' => 'prabayar', 'nama_pelanggan' => 'Budi',
            'nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => '2026-11-05 10:00:00',
        ]);
        $kirim(User::factory()->create(), $budi)->assertStatus(422)->assertJsonPath('message', 'Tagihan bulan ini sudah lunas');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/send'));
        $this->assertSame(0, PesanWa::count());
    }

    public function test_test_message_is_sent_from_branch_whatsapp(): void
    {
        $this->gateway();

        $this->actingAs(User::factory()->create())->postJson("/kelola-data/notifikasi/{$this->garut->id}/whatsapp/uji", ['nomor' => '+62 813-0000-9901'])->assertOk();
        $this->postJson("/kelola-data/notifikasi/{$this->garut->id}/whatsapp/uji", ['nomor' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('nomor');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "cabang-{$this->garut->id}/send") && $r['to'] === '6281300009901');
        $this->assertSame(['uji', 'terkirim'], [PesanWa::sole()->jenis, PesanWa::sole()->status]);
    }

    /** WA gateway palsu: WhatsApp Garut & Tasik terhubung. */
    private function gateway($kirim = null): void
    {
        $terhubung = fn (Lokasi $l, string $nomor) => ['id' => "cabang-{$l->id}", 'status' => 'connected', 'qr' => null, 'nomor' => $nomor, 'nama' => $l->nama_lokasi, 'sejak' => '2026-11-15T01:00:00Z', 'galat' => null];
        Http::fake([
            '127.0.0.1:3100/sessions' => Http::response(['sessions' => [$terhubung($this->garut, '6281300000001'), $terhubung($this->tasik, '6281300000002')]]),
            '127.0.0.1:3100/sessions/*/start' => Http::response(['id' => 'cabang-x', 'status' => 'qr', 'qr' => 'teks-qr', 'nomor' => null, 'nama' => null, 'sejak' => null, 'galat' => null]),
            '127.0.0.1:3100/sessions/*/send' => $kirim ?? Http::response(['id' => 'WAID1']),
            '127.0.0.1:3100/sessions/*' => Http::response($terhubung($this->garut, '6281300000001')),
        ]);
    }

    private function pelanggan(string $nama, string $tlp, Lokasi $lokasi, array $lain = []): Pelanggan
    {
        return Pelanggan::create($lain + [
            'id_pelanggan' => sprintf('MSS-%04d', Pelanggan::count() + 1), 'nama' => $nama, 'tlp' => $tlp, 'id_lokasi' => $lokasi->id, 'router_id' => $this->router->id, 'jns_koneksi' => 'static',
            'id_paket' => $this->paket->id, 'jns_tagihan' => 'prabayar', 'skema_tagihan' => 'full', 'tgl_registrasi' => '2026-10-01',
            'tgl_jatuh_tempo' => 10, 'status' => 'aktif',
        ]);
    }
}
