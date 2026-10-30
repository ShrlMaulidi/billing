<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Rekening;
use App\Models\Router;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Satu set data uji: cabang (+ tarif pajak Jan–Des), area, rekening, router, paket, dan pelanggan.
 * Jalankan manual: php artisan db:seed --class=DataUjiSeeder. Aman diulang (data diperbarui, tidak dobel).
 * Seeder tidak menghubungi Mikrotik: secret/profile tidak dibuat di router, dan router uji memakai IP contoh.
 */
class DataUjiSeeder extends Seeder
{
    public function run(): void
    {
        // Data uji tidak boleh masuk ke database produksi
        if (app()->isProduction()) {
            $this->command?->error('DataUjiSeeder tidak dijalankan di production.');

            return;
        }

        DB::transaction(function () {
            $tahun = today()->year;
            $lokasi = Lokasi::updateOrCreate(['kode_lokasi' => 'DEMO01'], [
                'nama_lokasi' => 'MSS Demo Garut', 'alamat_lokasi' => 'Jl. Ciledug No. 10, Garut', 'tlp_lokasi' => '0262123456',
                'tlp_owner' => '081200000001', 'nama_mitra' => 'Mitra Demo', 'terima_cash' => true, 'status' => 'aktif',
            ]);
            foreach (range(1, 12) as $bulan) {
                $lokasi->pajak()->updateOrCreate(['periode' => sprintf('%04d-%02d-01', $tahun, $bulan)], [
                    'ppn_persen' => 11, 'pph_persen' => 0.5, 'bhp_persen' => 0.5, 'uso_persen' => 1.25,
                    'admin_fee_tipe' => 'nominal', 'admin_fee' => 2500, 'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 30,
                ]);
            }

            $area = collect([['DEMO-A1', 'Garut Kota', 'Kel. Pakuwon, Kel. Ciwalen'], ['DEMO-A2', 'Tarogong', 'Tarogong Kidul & Kaler']])
                ->mapWithKeys(fn ($a) => [$a[0] => Area::updateOrCreate(['kode_area' => $a[0]], [
                    'nama_area' => $a[1], 'id_lokasi' => $lokasi->id, 'cakupan' => $a[2], 'status' => 'aktif',
                ])]);

            // Rekening transfer contoh untuk pilihan metode pembayaran
            Rekening::updateOrCreate(['id_lokasi' => $lokasi->id, 'no_rekening' => '1234500001'], [
                'nama_rekening' => 'BCA', 'atas_nama' => 'MSS Demo Garut', 'tampil_invoice' => true,
            ]);

            // Ganti host/username/password dengan router asli di menu Router untuk mencoba sinkronisasi
            $router = Router::updateOrCreate(['kode_router' => 'DEMO-R1'], [
                'nama_router' => 'Router Demo Garut', 'id_lokasi' => $lokasi->id, 'mode_koneksi' => 'langsung', 'host' => '192.168.88.1',
                'port_api' => 8728, 'ssl' => false, 'username' => 'billing', 'password' => 'ganti-password-ini', 'profile_isolir' => 'ISOLIR',
            ]);

            $paket = collect([
                ['Demo Home 10 Mbps', 150000, 'pppoe', '10M', '10M/10M'],
                ['Demo Home 20 Mbps', 200000, 'pppoe', '20M', '20M/20M'],
                ['Demo Hotspot 5 Mbps', 100000, 'hotspot', 'HS-5M', '5M/5M'],
                ['Demo Gratis (Free)', 0, 'pppoe', 'FREE', '5M/5M'],
            ])->mapWithKeys(fn ($p) => [$p[3] => Paket::updateOrCreate(['nama_paket' => $p[0]], [
                'harga' => $p[1], 'jenis' => 'bulanan', 'jns_koneksi' => $p[2], 'id_lokasi' => $lokasi->id, 'filter_lokasi' => true,
                'router_id' => $router->id, 'profile_mikrotik' => $p[3], 'rate_limit' => $p[4], 'buat_profile' => false, 'tampil_registrasi' => true,
            ])]);

            $awalBulan = today()->startOfMonth();
            $pelanggan = [
                ['DEMO001', 'Budi Santoso', 'pppoe', 'demo.budi', '10M', 'DEMO-A1', $awalBulan->copy()->subMonths(3), [], 'prabayar'],
                ['DEMO002', 'Siti Aminah', 'pppoe', 'demo.siti', '20M', 'DEMO-A1', $awalBulan->copy()->subMonths(2), [], 'pascabayar'],
                ['DEMO003', 'Andi Pratama', 'pppoe', 'demo.andi', '10M', 'DEMO-A2', $awalBulan->copy()->subMonth(),
                    ['status_langganan' => 'off', 'profile_asal' => '10M', 'isolir_at' => now()], 'prabayar'],
                ['DEMO004', 'Dewi Lestari', 'hotspot', 'demo.dewi', 'HS-5M', 'DEMO-A2', $awalBulan->copy()->addDays(2), [], 'prabayar'],
                ['DEMO005', 'Rudi Hartono', 'pppoe', 'demo.rudi', 'FREE', 'DEMO-A1', $awalBulan->copy(), [], 'prabayar'],
                ['DEMO006', 'Joko Susilo', 'pppoe', 'demo.joko', '20M', 'DEMO-A2', $awalBulan->copy()->subMonths(6), ['status' => 'berhenti'], 'prabayar'],
            ];
            foreach ($pelanggan as $i => [$id, $nama, $koneksi, $username, $profile, $kodeArea, $tglRegistrasi, $extra, $tagihan]) {
                Pelanggan::updateOrCreate(['id_pelanggan' => $id], [
                    'nama' => $nama, 'tlp' => sprintf('0812000001%02d', $i + 1), 'alamat' => 'Jl. Contoh No. '.($i + 1).', Garut',
                    'jns_tagihan' => $tagihan, 'id_lokasi' => $lokasi->id, 'id_area' => $area[$kodeArea]->id, 'id_paket' => $paket[$profile]->id,
                    'router_id' => $router->id, 'jns_koneksi' => $koneksi, 'username_pppoe' => $username,
                    'tgl_registrasi' => $tglRegistrasi->toDateString(), 'tgl_jatuh_tempo' => 10, 'ppn' => $i % 2 === 0,
                    'status' => 'aktif', 'status_langganan' => 'on', 'profile_asal' => null, 'isolir_at' => null, ...$extra,
                ]);
            }
        });

        $this->command?->info('Data uji dibuat: cabang MSS Demo Garut, 2 area, 1 rekening, 1 router, 4 paket, 6 pelanggan.');
    }
}
