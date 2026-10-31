<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** Halaman pertama website adalah landing page company profile, terbuka tanpa login dan hanya berisi data publik. */
    public function test_root_shows_public_landing_page_with_only_public_data(): void
    {
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut', 'alamat_lokasi' => 'Jl. Ciledug', 'tlp_lokasi' => '0262123', 'tlp_owner' => '0812999', 'nama_mitra' => 'Rahasia']);
        $paket = ['jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P', 'id_lokasi' => $garut->id, 'tampil_registrasi' => true];
        Paket::create([...$paket, 'nama_paket' => 'Home 20', 'harga' => 200000, 'rate_limit' => '10M/20M']);
        Paket::create([...$paket, 'nama_paket' => 'Home 10', 'harga' => 150000, 'rate_limit' => '512k']);
        Paket::create([...$paket, 'nama_paket' => 'Gratis', 'harga' => 0]);
        Paket::create([...$paket, 'nama_paket' => 'Internal', 'harga' => 99000, 'tampil_registrasi' => false]);

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('Landing/Index')
            ->where('paket', [
                ['nama' => 'Home 10', 'harga' => 150000, 'kecepatan' => '512 Kbps', 'koneksi' => 'pppoe', 'keterangan' => null],
                ['nama' => 'Home 20', 'harga' => 200000, 'kecepatan' => '20 Mbps', 'koneksi' => 'pppoe', 'keterangan' => null],
            ])
            ->missing('kantor'));
        $this->get('/login')->assertOk();
    }
}
