<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Pembayaran;
use App\Models\PenarikanSaldo;
use App\Models\SetoranMitra;
use App\Support\RekapCabang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RekapCabangTest extends TestCase
{
    use RefreshDatabase;

    public function test_balances_for_several_months_are_computed_per_payment_month(): void
    {
        $this->travelTo('2026-11-15 10:00:00');
        $garut = Lokasi::create(['kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut']);
        $cimahi = Lokasi::create(['kode_lokasi' => 'C002', 'nama_lokasi' => 'MSS Cimahi', 'admin_full' => true]);
        foreach ([$garut, $cimahi] as $lokasi) {
            $lokasi->pajak()->create([
                'periode' => '2026-01-01', 'ppn_persen' => 0, 'pph_persen' => 0, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 2500,
                'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 40,
            ]);
        }
        // Garut September: 2 transaksi cash (detik terakhir bulan ikut September), setor 100.000
        $this->bayar($garut, 600000, 'cash', '2026-09-10 08:00:00');
        $this->bayar($garut, 400000, 'cash', '2026-09-30 23:59:59');
        SetoranMitra::create(['id_lokasi' => $garut->id, 'periode' => '2026-09-01', 'nominal' => 100000, 'metode' => 'cash']);
        // Garut Oktober: payment gateway perusahaan (uang di MSS), WD 100.000 disetujui; WD pending tidak mengurangi saldo
        $this->bayar($garut, 500000, 'xendit', '2026-10-01 00:00:00');
        $this->penarikan($garut, 100000, 'disetujui');
        $this->penarikan($garut, 50000, 'pending');
        // Cimahi Admin Full: payment gateway masuk ke mitra
        $this->bayar($cimahi, 500000, 'xendit', '2026-10-20 12:00:00');

        $saldo = RekapCabang::saldo(Lokasi::orderBy('id')->get(), ['2026-09-01', '2026-10-01', '2026-11-01']);

        // Sep: pendapatan reseller 1.000.000 − admin 2×2.500 − bagi hasil 400.000 = 595.000; via mitra 1.000.000; + setor 100.000
        // Okt: 500.000 − 2.500 − 200.000 = 297.500; via mitra 0 (gateway perusahaan); − WD 100.000
        $this->assertSame(['2026-09-01' => -305000, '2026-10-01' => 197500, '2026-11-01' => 0], $saldo[$garut->id]);
        // Cimahi Admin Full Okt: 297.500 − via mitra 500.000
        $this->assertSame(['2026-09-01' => 0, '2026-10-01' => -202500, '2026-11-01' => 0], $saldo[$cimahi->id]);

        $oktober = RekapCabang::hitung(Lokasi::whereKey($garut->id)->get(), '2026-10-01')->first();
        // hitung() bawaan tetap menghitung jumlah pelanggan (0, bukan null)
        $this->assertSame([500000, 0, 500000, 2500, 100000, 0], [$oktober['pemasukan'], $oktober['total_cash'], $oktober['total_pg'], $oktober['admin_fee'], $oktober['total_wd'], $oktober['jumlah_pelanggan']]);
        $this->assertSame([$garut->id => ['2026-10-01' => 147500]], RekapCabang::saldoTersedia(Lokasi::whereKey($garut->id)->get(), ['2026-10-01']));
    }

    private function bayar(Lokasi $lokasi, int $nominal, string $metode, string $waktu): void
    {
        Pembayaran::create([
            'id_lokasi' => $lokasi->id, 'periode' => substr($waktu, 0, 8).'01', 'nama_pelanggan' => 'P', 'nama_paket' => 'P',
            'harga' => $nominal, 'jml_bayar' => $nominal, 'metode' => $metode, 'paid_at' => $waktu,
        ]);
    }

    private function penarikan(Lokasi $lokasi, int $nominal, string $status): void
    {
        PenarikanSaldo::create([
            'id_lokasi' => $lokasi->id, 'nama_request' => 'WD', 'periode_sumber' => '2026-10-01', 'nominal' => $nominal, 'status' => $status,
            'keterangan' => 'Bagi hasil', 'bank_penerima' => 'BCA', 'no_rekening_penerima' => '123', 'atas_nama_penerima' => 'Mitra',
        ]);
    }
}
