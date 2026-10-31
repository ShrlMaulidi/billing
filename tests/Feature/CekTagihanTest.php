<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Rekening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CekTagihanTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $garut;

    private Paket $paket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $this->garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut', 'tlp_lokasi' => '0262123']);
        $this->paket = Paket::create(['nama_paket' => 'Home 20', 'harga' => 200000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P']);
    }

    public function test_guest_finds_unpaid_bills_by_customer_id_with_masked_name_and_no_private_data(): void
    {
        // Prabayar sejak Agustus, Agustus sudah dibayar: September & Oktober belum
        $budi = $this->pelanggan(['id_pelanggan' => 'MSS001', 'nama' => 'Budi Santoso', 'tgl_registrasi' => '2026-08-01', 'tgl_jatuh_tempo' => 10, 'alamat' => 'Jl. Rahasia 1']);
        Pembayaran::create(['pelanggan_id' => $budi->id, 'id_lokasi' => $this->garut->id, 'periode' => '2026-08-01', 'jns_tagihan' => 'prabayar', 'nama_pelanggan' => 'Budi', 'nama_paket' => 'Home 20', 'harga' => 200000, 'jml_bayar' => 200000, 'metode' => 'cash', 'paid_at' => '2026-08-05 10:00:00']);
        Rekening::create(['nama_rekening' => 'BCA', 'atas_nama' => 'MSS', 'no_rekening' => '111', 'tampil_invoice' => true]);
        Rekening::create(['nama_rekening' => 'BRI', 'atas_nama' => 'Internal', 'no_rekening' => '222', 'tampil_invoice' => false]);

        $this->get('/cek-tagihan')->assertOk()->assertInertia(fn ($page) => $page->component('CekTagihan/Index')->where('hasil', null));
        $this->post('/cek-tagihan', ['cari' => 'MSS001'])->assertOk()->assertInertia(fn ($page) => $page
            ->has('hasil', 1)
            ->where('hasil.0.nama', 'Bu** Sa*****')
            ->has('hasil.0.tagihan', 2)
            ->where('hasil.0.tagihan.0', [
                'no_tagihan' => sprintf('TGH202609-%05d', $budi->id), 'nama_paket' => 'Home 20', 'harga_paket' => 200000, 'periode_pembayaran' => '2026-09-01',
                'periode' => '2026-09-01', 'nominal' => 200000, 'is_prorate' => false, 'hari_prorate' => null, 'jatuh_tempo' => '2026-09-10',
            ])
            ->where('hasil.0.tagihan.1.periode', '2026-10-01')
            ->where('hasil.0.cabang', ['nama_lokasi' => 'MSS Garut', 'alamat_lokasi' => null, 'tlp_lokasi' => '0262123'])
            ->where('hasil.0.pembayaran_terakhir', ['periode_pembayaran' => '2026-08-01', 'paid_at' => '2026-08-05'])
            ->where('hasil.0.rekening', [['nama_rekening' => 'BCA', 'no_rekening' => '111', 'atas_nama' => 'MSS']])
            ->missing('hasil.0.alamat')->missing('hasil.0.tlp')->missing('hasil.0.username_pppoe'));
    }

    public function test_phone_number_matches_in_any_format_and_postpaid_bills_follow_usage_month(): void
    {
        // Pascabayar registrasi September: tagihan Oktober untuk pemakaian September
        $this->pelanggan(['id_pelanggan' => 'MSS002', 'nama' => 'Sari', 'tlp' => '0812-3456-7890', 'jns_tagihan' => 'pascabayar', 'tgl_registrasi' => '2026-09-01']);

        foreach (['081234567890', '6281234567890', '+62 812 3456 7890'] as $nomor) {
            $this->post('/cek-tagihan', ['cari' => $nomor])->assertInertia(fn ($page) => $page
                ->has('hasil', 1)->where('hasil.0.id_pelanggan', 'MSS002')
                ->where('hasil.0.tagihan.0.periode_pembayaran', '2026-10-01')->where('hasil.0.tagihan.0.periode', '2026-09-01')->has('hasil.0.tagihan', 1));
        }
        // Sebagian nomor tidak cukup untuk menemukan pelanggan
        $this->post('/cek-tagihan', ['cari' => '34567890'])->assertInertia(fn ($page) => $page->has('hasil', 0));
    }

    public function test_stopped_customers_are_not_found_and_invalid_input_is_rejected(): void
    {
        $this->pelanggan(['id_pelanggan' => 'MSS003', 'status' => 'berhenti', 'tgl_registrasi' => '2026-01-01']);

        $this->post('/cek-tagihan', ['cari' => 'MSS003'])->assertInertia(fn ($page) => $page->has('hasil', 0));
        $this->from('/cek-tagihan')->post('/cek-tagihan', ['cari' => "x' OR 1=1 --"])->assertSessionHasErrors('cari');
        $this->from('/cek-tagihan')->post('/cek-tagihan', ['cari' => ''])->assertSessionHasErrors('cari');
    }

    public function test_search_is_rate_limited_per_ip(): void
    {
        foreach (range(1, 10) as $_) {
            $this->post('/cek-tagihan', ['cari' => 'TIDAKADA'])->assertOk();
        }
        $this->from('/cek-tagihan')->post('/cek-tagihan', ['cari' => 'TIDAKADA'])
            ->assertRedirect('/cek-tagihan')->assertSessionHasErrors(['cari' => 'Terlalu banyak percobaan. Coba lagi dalam 1 menit.']);
    }

    private function pelanggan(array $attrs): Pelanggan
    {
        return Pelanggan::create([
            'nama' => 'Pelanggan', 'username_pppoe' => uniqid('u'), 'id_lokasi' => $this->garut->id, 'id_paket' => $this->paket->id,
            'jns_tagihan' => 'prabayar', 'status' => 'aktif', 'tgl_jatuh_tempo' => 10, ...$attrs,
        ]);
    }
}
