<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Models\User;
use App\Services\AkunPelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfoPelangganTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_customer_information_page_with_public_packages(): void
    {
        Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'rate_limit' => '10M/20M', 'tampil_registrasi' => true]);
        Paket::create(['nama_paket' => 'Internal', 'harga' => 50000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'tampil_registrasi' => false]);

        $this->get('/pelanggan')->assertOk()->assertInertia(fn ($page) => $page->component('InfoPelanggan/Index')
            ->where('hasil', null)
            ->where('masuk', null)
            ->has('paket', 1)
            ->where('paket.0.nama', 'Home 20 Mbps')
            ->where('paket.0.kecepatan', '20 Mbps'));
    }

    public function test_bill_check_finds_customer_by_phone_with_masked_name(): void
    {
        $this->travelTo('2026-11-15 09:00:00');
        $this->pelanggan();

        $this->post('/pelanggan/cek-tagihan', ['cari' => '+62 813-0000-9901'])->assertOk()->assertInertia(fn ($page) => $page->component('InfoPelanggan/Index')
            ->where('cari', '+62 813-0000-9901')
            ->has('hasil', 1)
            ->where('hasil.0.nama', fn (string $nama) => $nama !== 'Budi Santoso' && str_starts_with($nama, 'B'))
            ->where('hasil.0.tagihan.0.nominal', 200000));

        $this->post('/pelanggan/cek-tagihan', ['cari' => '089999999999'])->assertInertia(fn ($page) => $page->has('hasil', 0));
        $this->post('/pelanggan/cek-tagihan', ['cari' => '<script>'])->assertSessionHasErrors('cari');
        $this->get('/pelanggan/cek-tagihan')->assertRedirect('/pelanggan');
    }

    public function test_staff_is_sent_to_data_pelanggan_and_customer_sees_dashboard_button(): void
    {
        $this->actingAs(User::factory()->create())->get('/pelanggan?cabang=3')->assertRedirect('/pelanggan/data?cabang=3');

        $akun = $this->pelanggan()->user;
        $akun->update(['wajib_ganti_password' => false]);
        $this->actingAs($akun)->get('/pelanggan')->assertOk()->assertInertia(fn ($page) => $page->where('masuk', '/akun-saya'));
    }

    private function pelanggan(): Pelanggan
    {
        $lokasi = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $router = Router::create([
            'nama_router' => 'CCR Pusat', 'mode_koneksi' => 'langsung', 'host' => '203.0.113.10', 'port_api' => 8728,
            'username' => 'billing', 'password' => 'rahasia', 'profile_isolir' => 'ISOLIR',
        ]);
        $paket = Paket::create(['nama_paket' => 'Home 20 Mbps', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'static']);
        $pelanggan = Pelanggan::create([
            'nama' => 'Budi Santoso', 'tlp' => '081300009901', 'id_lokasi' => $lokasi->id, 'router_id' => $router->id, 'jns_koneksi' => 'static',
            'id_paket' => $paket->id, 'jns_tagihan' => 'prabayar', 'skema_tagihan' => 'full', 'tgl_registrasi' => '2026-11-01', 'tgl_jatuh_tempo' => 10, 'status' => 'aktif',
        ]);
        app(AkunPelanggan::class)->sinkronkan($pelanggan);

        return $pelanggan->fresh();
    }
}
