<?php

namespace Database\Seeders;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\PelangganInstansi;
use App\Models\Pembayaran;
use App\Models\PembayaranInstansi;
use App\Models\SetoranMitra;
use App\Support\RekapCabang;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data uji menu Laporan: transaksi 3 bulan terakhir (2 bulan lalu, bulan lalu, bulan berjalan) untuk keempat tipe pelanggan,
 * masing-masing berisi pelanggan lunas, belum lunas, dan bayar sebagian.
 * Jalankan manual: php artisan db:seed --class=LaporanUjiSeeder. Aman diulang (data diperbarui, tidak dobel); tidak dijalankan di production.
 *
 * Pola bayar per bulan (B = bulan berjalan): 'penuh' | 'setengah' | null (belum bayar).
 * Shafa.net: pelanggan end user selalu bayar penuh per bulan, jadi "sebagian" terlihat di mode Tahunan (sebagian bulan sudah dibayar).
 */
class LaporanUjiSeeder extends Seeder
{
    private const KETERANGAN_SETORAN = 'Data uji laporan';

    /** @var list<string> Y-m-01: [2 bulan lalu, bulan lalu, bulan berjalan] */
    private array $bulan = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('LaporanUjiSeeder tidak dijalankan di production.');

            return;
        }
        $this->bulan = array_map(fn (int $mundur) => today()->startOfMonth()->subMonths($mundur)->format('Y-m-d'), [2, 1, 0]);

        DB::transaction(function () {
            $this->mitra();
            $this->shafaNet();
            $this->instansi();
        });

        $this->command?->info('Data uji laporan dibuat: 3 mitra reseller, 3 lokasi Shafa.net (11 pelanggan), 4 korporat, 4 instansi pemerintah.');
    }

    /** Mitra reseller: pelanggan end user membayar cash ke mitra, mitra menyetor kewajiban ke MSS. */
    private function mitra(): void
    {
        $mitra = [
            ['UJI-R1', 'MSS Uji Tarogong', 'Asep Sunandar', ['penuh', 'penuh', 'penuh']],
            ['UJI-R2', 'MSS Uji Cimahi', 'Dadang Hermawan', ['penuh', 'penuh', 'setengah']],
            ['UJI-R3', 'MSS Uji Leles', 'Ujang Rahmat', ['penuh', null, null]],
        ];
        foreach ($mitra as $i => [$kode, $nama, $pemilik, $setor]) {
            $lokasi = $this->cabang($kode, 'reseller', $nama, $pemilik, ['ppn_persen' => 11, 'pph_persen' => 0.5, 'admin_fee' => 2500, 'nilai_bagi_hasil' => 30]);
            $paket = $this->paket("Uji {$nama} 20 Mbps", 200000, $lokasi);
            $pelanggan = collect(range(1, 4))->map(fn (int $n) => $this->pelangganEndUser(sprintf('%s-%02d', $kode, $n), "Pelanggan {$nama} {$n}", $lokasi, $paket));

            foreach ($this->bulan as $b => $periode) {
                $pelanggan->each(fn (Pelanggan $p) => $this->bayarEndUser($p, $paket, $periode));
                $kewajiban = RekapCabang::hitung($lokasi->newCollection([$lokasi]), $periode)->first()['harus_setor'];
                $nominal = match ($setor[$b]) {
                    'penuh' => $kewajiban,
                    'setengah' => intdiv($kewajiban, 2),
                    default => 0,
                };
                $this->setoran($lokasi, $periode, $nominal);
            }
        }
    }

    /** Shafa.net (retail): beberapa lokasi dengan pola bayar berbeda agar bisa dibandingkan; pelanggan end user dikelola langsung ISP. */
    private function shafaNet(): void
    {
        $lokasi = [
            ['UJI-SHAFA', 'Shafa.net Garut Kota', 250000, [
                ['Andi Firmansyah', [true, true, true]], ['Budi Setiawan', [true, true, false]],
                ['Cici Paramida', [true, false, false]], ['Dedi Kurnia', [false, false, false]],
            ]],
            ['UJI-SHAFA2', 'Shafa.net Tarogong', 200000, [
                ['Euis Komalasari', [true, true, true]], ['Fajar Nugraha', [true, true, true]], ['Gilang Ramadhan', [true, true, false]],
            ]],
            ['UJI-SHAFA3', 'Shafa.net Bayongbong', 150000, [
                ['Hana Pertiwi', [true, false, false]], ['Irfan Hakim', [false, false, false]],
                ['Joni Iskandar', [true, true, true]], ['Kiki Amalia', [false, false, false]],
            ]],
        ];
        // ID pelanggan berurutan lintas lokasi (UJI-S01, UJI-S02, ...) agar seeder lama yang diulang tidak membuat data ganda
        $urut = 0;
        foreach ($lokasi as [$kode, $nama, $harga, $pelanggan]) {
            $cabang = $this->cabang($kode, 'retail', $nama, null, ['ppn_persen' => 11, 'pph_persen' => 0.5, 'admin_fee' => 0, 'nilai_bagi_hasil' => 0]);
            $paket = $this->paket("Uji {$nama} Home", $harga, $cabang);
            foreach ($pelanggan as [$namaPelanggan, $bayar]) {
                $p = $this->pelangganEndUser(sprintf('UJI-S%02d', ++$urut), $namaPelanggan, $cabang, $paket);
                foreach ($this->bulan as $b => $periode) {
                    $bayar[$b] ? $this->bayarEndUser($p, $paket, $periode) : $p->pembayaran()->where('periode', $periode)->delete();
                }
            }
        }
    }

    /** Korporat & instansi pemerintah: harga per bulan + PPN, dibayar lewat Pembayaran Masuk. */
    private function instansi(): void
    {
        $awal = $this->bulan[0];
        $data = [
            ['korporat', 'PT Sinar Garut Sejahtera', '100 Mbps', 5000000, 11, ['penuh', 'penuh', 'penuh'], []],
            ['korporat', 'PT Berkah Media Nusantara', '50 Mbps', 3500000, 11, ['penuh', 'penuh', 'setengah'], []],
            ['korporat', 'Yayasan RS Medika Garut', '200 Mbps', 7500000, 11, ['penuh', null, null], []],
            // Berhenti awal bulan berjalan: tunggakan bulan lalu tetap terlihat di mode Tahunan
            ['korporat', 'SMK Swasta Al-Ihsan', '30 Mbps', 1500000, 11, ['penuh', null, null], ['status' => 'berhenti', 'tanggal_berhenti' => $this->bulan[2]]],
            // Instansi pemungut PPN membayar tanpa PPN
            ['pemerintah', 'Dinas Kominfo Kab. Garut', '100 Mbps', 4000000, 0, ['penuh', 'penuh', 'penuh'], []],
            ['pemerintah', 'RSUD dr. Slamet Garut', '300 Mbps', 10000000, 11, ['penuh', 'penuh', 'setengah'], []],
            ['pemerintah', 'Kantor Kemenag Kab. Garut', '50 Mbps', 2500000, 11, [null, 'setengah', 'setengah'], []],
            ['pemerintah', 'SMA Negeri 1 Garut', '50 Mbps', 2000000, 11, [null, null, null], []],
        ];
        foreach ($data as $i => [$tipe, $nama, $kapasitas, $harga, $ppn, $bayar, $extra]) {
            $pelanggan = PelangganInstansi::updateOrCreate(['tipe' => $tipe, 'nama' => $nama], [
                'alamat' => 'Jl. Uji Coba No. '.($i + 1).', Garut', 'kapasitas' => $kapasitas, 'harga' => $harga,
                'npwp' => sprintf('0123456789%05d', $i + 1), 'tanggal_aktif' => $awal, 'tanggal_berhenti' => null,
                'jns_tagihan' => 'prabayar', 'status' => 'berlangganan', ...$extra,
            ]);
            foreach ($this->bulan as $b => $periode) {
                $nominal = match ($bayar[$b]) {
                    'penuh' => $harga,
                    'setengah' => intdiv($harga, 2),
                    default => 0,
                };
                if ($nominal === 0) {
                    $pelanggan->pembayaran()->where('periode', $periode)->delete();

                    continue;
                }
                $ppnNominal = (int) round($nominal * $ppn / 100);
                PembayaranInstansi::updateOrCreate(['pelanggan_instansi_id' => $pelanggan->id, 'periode' => $periode], [
                    'nominal' => $nominal, 'ppn_persen' => $ppn, 'ppn' => $ppnNominal, 'total' => $nominal + $ppnNominal,
                ]);
            }
        }
    }

    /** Cabang + tarif pajak tiap bulan uji & sepanjang tahun berjalan. */
    private function cabang(string $kode, string $tipe, string $nama, ?string $pemilik, array $tarif): Lokasi
    {
        $lokasi = Lokasi::updateOrCreate(['kode_lokasi' => $kode], [
            'tipe' => $tipe, 'nama_lokasi' => $nama, 'alamat_lokasi' => "Jl. {$nama}, Garut", 'nama_mitra' => $pemilik,
            'terima_cash' => true, 'admin_full' => false, 'status' => 'aktif',
        ]);
        $periode = collect(range(1, 12))->map(fn (int $b) => sprintf('%04d-%02d-01', today()->year, $b))->merge($this->bulan)->unique();
        foreach ($periode as $p) {
            $lokasi->pajak()->updateOrCreate(['periode' => $p], [
                'bhp_persen' => 0, 'uso_persen' => 0, 'admin_fee_tipe' => 'nominal', 'metode_bagi_hasil' => 'persentase', ...$tarif,
            ]);
        }

        return $lokasi;
    }

    private function paket(string $nama, int $harga, Lokasi $lokasi): Paket
    {
        return Paket::updateOrCreate(['nama_paket' => $nama], [
            'harga' => $harga, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'id_lokasi' => $lokasi->id, 'filter_lokasi' => true,
            'profile_mikrotik' => 'UJI', 'rate_limit' => '20M/20M', 'buat_profile' => false, 'tampil_registrasi' => false,
        ]);
    }

    private function pelangganEndUser(string $id, string $nama, Lokasi $lokasi, Paket $paket): Pelanggan
    {
        return Pelanggan::updateOrCreate(['id_pelanggan' => $id], [
            'nama' => $nama, 'alamat' => "Jl. {$nama}, Garut", 'jns_tagihan' => 'prabayar', 'skema_tagihan' => 'full',
            'id_lokasi' => $lokasi->id, 'id_paket' => $paket->id, 'jns_koneksi' => 'pppoe', 'username_pppoe' => strtolower(str_replace(' ', '.', $id)),
            // Terdaftar tanggal 1 bulan uji pertama: tagihan muncul tepat untuk 3 bulan uji (tanpa prorate)
            'tgl_registrasi' => $this->bulan[0], 'tgl_jatuh_tempo' => 10,
            'status' => 'aktif', 'status_langganan' => 'on',
        ]);
    }

    /** Pembayaran cash satu periode, dibayar tanggal 1 bulan itu. */
    private function bayarEndUser(Pelanggan $pelanggan, Paket $paket, string $periode): void
    {
        Pembayaran::updateOrCreate(['pelanggan_id' => $pelanggan->id, 'periode' => $periode], [
            'no_invoice' => sprintf('UJI%s-%05d', Carbon::parse($periode)->format('Ym'), $pelanggan->id),
            'id_lokasi' => $pelanggan->id_lokasi, 'jns_tagihan' => 'prabayar', 'nama_pelanggan' => $pelanggan->nama, 'nama_paket' => $paket->nama_paket,
            'harga' => $paket->harga, 'diskon' => 0, 'jml_bayar' => $paket->harga, 'metode' => 'cash', 'paid_at' => "{$periode} 09:00:00",
        ]);
    }

    private function setoran(Lokasi $lokasi, string $periode, int $nominal): void
    {
        $kunci = ['id_lokasi' => $lokasi->id, 'periode' => $periode, 'keterangan' => self::KETERANGAN_SETORAN];
        if ($nominal <= 0) {
            SetoranMitra::where($kunci)->delete();

            return;
        }
        SetoranMitra::updateOrCreate($kunci, ['nominal' => $nominal, 'metode' => 'cash']);
    }
}
