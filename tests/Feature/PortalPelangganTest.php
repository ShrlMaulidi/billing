<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Router;
use App\Models\Tiket;
use App\Models\TransaksiMidtrans;
use App\Models\User;
use App\Services\AkunPelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalPelangganTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $lokasi;

    private Router $router;

    private Paket $paket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 09:00:00');
        $this->lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $this->router = Router::create([
            'nama_router' => 'CCR Pusat', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.10', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR',
        ]);
        $this->paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'static']);
    }

    public function test_home_shows_unpaid_invoices_of_own_subscriptions_only(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890');
        $this->bayar($budi, '2026-10-01');
        $lain = $this->pelanggan('Orang Lain', '089900001111');
        Tiket::create(['no_tiket' => 'TKT2611-00001', 'user_id' => $budi->user_id, 'pelanggan_id' => $budi->id, 'kategori' => 'gangguan', 'judul' => 'Lambat']);
        Tiket::create(['no_tiket' => 'TKT2611-00002', 'user_id' => $lain->user_id, 'pelanggan_id' => $lain->id, 'kategori' => 'gangguan', 'judul' => 'Mati']);

        $this->actingAs($budi->user)->get('/akun-saya')->assertOk()->assertInertia(fn ($page) => $page->component('Portal/Home')
            ->has('hasil', 1)
            ->where('hasil.0.nama', 'Budi')
            ->has('hasil.0.tagihan', 1)
            ->where('hasil.0.tagihan.0.periode', '2026-11-01')
            ->where('hasil.0.tagihan.0.nominal', 200000)
            ->where('hasil.0.lunas.0.periode_pembayaran', '2026-10-01')
            ->where('bulanIni', '2026-11-01')
            ->where('hariIni', '2026-11-15')
            ->where('tiketAktif', 1));
    }

    public function test_billing_history_lists_own_payments_with_receipt_link(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890');
        $this->bayar($budi, '2026-10-01');
        $this->bayar($this->pelanggan('Orang Lain', '089900001111'), '2026-10-01');

        $this->actingAs($budi->user)->get('/akun-saya/riwayat')->assertOk()->assertInertia(fn ($page) => $page->component('Portal/Riwayat')
            ->has('rows', 1)
            ->where('rows.0.periode', '2026-10-01')
            ->where('rows.0.jml_bayar', 200000)
            ->where('rows.0.link_kwitansi', fn (string $link) => str_contains($link, '/cek-tagihan/kwitansi/') && str_contains($link, 'signature=')));
    }

    public function test_paid_invoice_uses_amount_at_payment_time_and_is_stamped_paid(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890');
        $pembayaran = $this->bayar($budi, '2026-10-01');
        $this->paket->update(['nama_paket' => 'Home 30 Mbps', 'harga' => 300000]);

        $this->actingAs($budi->user)->get("/akun-saya/riwayat/{$pembayaran->id}/invoice")->assertOk()->assertInertia(fn ($page) => $page->component('Portal/Dokumen')
            ->where('jenis', 'invoice')
            ->where('tagihan.no_tagihan', sprintf('TGH202610-%05d', $budi->id))
            ->where('tagihan.nama_paket', 'Home 20 Mbps')
            ->where('tagihan.nominal', 200000)
            ->where('tagihan.nama', 'Budi')
            ->where('tagihan.pembayaran_id', $pembayaran->id));
    }

    public function test_receipt_shows_payment_without_staff_name(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890');
        $pembayaran = $this->bayar($budi, '2026-10-01');

        $this->actingAs($budi->user)->get("/akun-saya/riwayat/{$pembayaran->id}/kuitansi")->assertOk()->assertInertia(fn ($page) => $page->component('Portal/Dokumen')
            ->where('jenis', 'kuitansi')
            ->where('kwitansi.jml_bayar', 200000)
            ->where('kwitansi.nama_pelanggan', 'Budi')
            ->where('kwitansi.metode_label', 'Cash')
            ->missing('kwitansi.petugas'));
    }

    public function test_invoice_and_receipt_of_another_customer_return_404(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890');
        $milikLain = $this->bayar($this->pelanggan('Orang Lain', '089900001111'), '2026-10-01');

        $this->actingAs($budi->user)->get("/akun-saya/riwayat/{$milikLain->id}/invoice")->assertNotFound();
        $this->get("/akun-saya/riwayat/{$milikLain->id}/kuitansi")->assertNotFound();
    }

    public function test_customer_returning_from_midtrans_lands_on_dashboard_result_page(): void
    {
        $budi = $this->pelanggan('Budi Santoso', '081234567890');
        $pembayaran = $this->bayar($budi, '2026-11-01');
        $transaksi = TransaksiMidtrans::create([
            'order_id' => 'MSSB-202611-'.$budi->id.'-uji', 'pelanggan_id' => $budi->id, 'periode' => '2026-11-01', 'gross_amount' => 200000,
            'status' => 'lunas', 'payment_type' => 'bank_transfer', 'pembayaran_id' => $pembayaran->id,
        ]);

        $this->actingAs($budi->user)->get('/cek-tagihan/selesai?order_id='.$transaksi->order_id)
            ->assertRedirect('/akun-saya/pembayaran?order_id='.$transaksi->order_id);
        $this->get('/akun-saya/pembayaran?order_id='.$transaksi->order_id)->assertOk()->assertInertia(fn ($page) => $page->component('Portal/PembayaranSelesai')
            ->where('transaksi.status', 'lunas')
            ->where('transaksi.nama', 'Budi Santoso')
            ->where('transaksi.pembayaran_id', $pembayaran->id)
            ->where('transaksi.lanjut_url', null));
    }

    public function test_result_page_of_another_customers_payment_is_not_shown_in_dashboard(): void
    {
        $lain = $this->pelanggan('Orang Lain', '089900001111');
        $transaksi = TransaksiMidtrans::create([
            'order_id' => 'MSSB-202611-'.$lain->id.'-uji', 'pelanggan_id' => $lain->id, 'periode' => '2026-11-01', 'gross_amount' => 200000, 'status' => 'lunas',
        ]);
        $budi = $this->pelanggan('Budi', '081234567890');

        // Bukan pemilik: tetap halaman publik (nama disamarkan), halaman dashboard 404
        $this->actingAs($budi->user)->get('/cek-tagihan/selesai?order_id='.$transaksi->order_id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('CekTagihan/Selesai'));
        $this->get('/akun-saya/pembayaran?order_id='.$transaksi->order_id)->assertNotFound();
    }

    public function test_account_page_lists_subscriptions_using_this_account(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890');

        $this->actingAs($budi->user)->get('/akun-saya/akun')->assertOk()->assertInertia(fn ($page) => $page->component('Portal/Akun')
            ->where('akun.username', '081234567890')
            ->has('langganan', 1)
            ->where('langganan.0.paket', 'Home 20 Mbps')
            ->where('langganan.0.cabang', 'MSS Garut'));
    }

    public function test_member_card_is_offered_only_for_active_subscriptions(): void
    {
        $budi = $this->pelanggan('Budi', '081234567890');

        $this->actingAs($budi->user)->get('/akun-saya/akun')->assertInertia(fn ($page) => $page
            ->where('langganan.0.link_kartu', fn (string $link) => str_contains($link, "/cek-tagihan/kartu/{$budi->id}") && str_contains($link, 'signature=')));

        Pelanggan::whereKey($budi->id)->update(['status' => 'berhenti']);
        $this->actingAs($budi->user)->get('/akun-saya/akun')->assertInertia(fn ($page) => $page->where('langganan.0.link_kartu', null));
    }

    public function test_customer_uploads_and_replaces_own_profile_photo(): void
    {
        Storage::fake('local');
        $user = $this->pelanggan('Budi', '081234567890')->user;

        $this->actingAs($user)->post('/akun-saya/foto', ['foto' => UploadedFile::fake()->image('foto.jpg', 400, 400)])->assertOk();
        $pertama = $user->fresh()->foto;
        Storage::disk('local')->assertExists($pertama);
        $this->assertStringStartsWith(User::FOLDER_FOTO.'/', $pertama);

        $this->actingAs($user)->get('/akun-saya/akun')->assertInertia(fn ($page) => $page->where('auth.user.foto', fn (string $url) => str_starts_with($url, '/akun-saya/foto?v=')));
        $this->actingAs($user)->get('/akun-saya/foto')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($user)->post('/akun-saya/foto', ['foto' => UploadedFile::fake()->image('baru.png', 300, 300)])->assertOk();
        Storage::disk('local')->assertMissing($pertama);
        Storage::disk('local')->assertExists($user->fresh()->foto);
    }

    public function test_profile_photo_must_be_a_raster_image(): void
    {
        Storage::fake('local');
        $user = $this->pelanggan('Budi', '081234567890')->user;
        $svg = UploadedFile::fake()->createWithContent('foto.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');

        $this->actingAs($user)->postJson('/akun-saya/foto', ['foto' => $svg])->assertUnprocessable()->assertJsonValidationErrors('foto');
        $this->actingAs($user)->postJson('/akun-saya/foto', ['foto' => UploadedFile::fake()->create('foto.pdf', 100, 'application/pdf')])->assertUnprocessable();
        $this->actingAs($user)->postJson('/akun-saya/foto', ['foto' => UploadedFile::fake()->image('besar.jpg', 400, 400)->size(3000)])->assertUnprocessable();
        $this->assertNull($user->fresh()->foto);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_customer_deletes_profile_photo(): void
    {
        Storage::fake('local');
        $user = $this->pelanggan('Budi', '081234567890')->user;
        $this->actingAs($user)->post('/akun-saya/foto', ['foto' => UploadedFile::fake()->image('foto.jpg', 200, 200)]);
        $path = $user->fresh()->foto;

        $this->actingAs($user)->deleteJson('/akun-saya/foto')->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($user->fresh()->foto);
        $this->actingAs($user)->get('/akun-saya/foto')->assertNotFound();
    }

    public function test_profile_photo_routes_are_refused_for_staff(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/akun-saya/foto', ['foto' => UploadedFile::fake()->image('foto.jpg', 200, 200)])->assertForbidden();
    }

    public function test_customer_pages_are_refused_for_staff(): void
    {
        $this->actingAs(User::factory()->create())->get('/akun-saya/riwayat')->assertForbidden();
    }

    /** Pelanggan aktif sejak Oktober 2026 dengan akun login yang sudah membuat password sendiri. */
    private function pelanggan(string $nama, string $tlp): Pelanggan
    {
        $pelanggan = Pelanggan::create([
            'nama' => $nama, 'tlp' => $tlp, 'id_lokasi' => $this->lokasi->id, 'router_id' => $this->router->id, 'jns_koneksi' => 'static',
            'id_paket' => $this->paket->id, 'jns_tagihan' => 'prabayar', 'skema_tagihan' => 'full', 'tgl_registrasi' => '2026-10-01', 'status' => 'aktif',
        ]);
        app(AkunPelanggan::class)->sinkronkan($pelanggan)->update(['wajib_ganti_password' => false]);

        return $pelanggan->fresh();
    }

    private function bayar(Pelanggan $pelanggan, string $periode): Pembayaran
    {
        return Pembayaran::create([
            'pelanggan_id' => $pelanggan->id, 'id_lokasi' => $this->lokasi->id, 'periode' => $periode, 'jns_tagihan' => 'prabayar', 'nama_pelanggan' => $pelanggan->nama,
            'nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => '2026-10-05 10:00:00',
        ]);
    }
}
