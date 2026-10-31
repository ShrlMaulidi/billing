<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * Tarif pajak & bagi hasil satu cabang untuk satu bulan.
 * Bulan yang belum punya baris mengikuti tarif bulan tersimpan sebelumnya.
 * Kolom periode sengaja tanpa cast date: disimpan & dibandingkan sebagai teks Y-m-d (tanpa jam) di MySQL maupun SQLite.
 */
#[Table('pajak_cabang')]
#[Fillable(['id_lokasi', 'periode', 'ppn_persen', 'pph_persen', 'bhp_persen', 'uso_persen', 'admin_fee_tipe', 'admin_fee', 'metode_bagi_hasil', 'nilai_bagi_hasil'])]
class PajakCabang extends Model
{
    /** Kolom tarif yang diisi dari form (Tambah Cabang & Pengaturan Pajak). */
    public const TARIF = ['ppn_persen', 'pph_persen', 'bhp_persen', 'uso_persen', 'admin_fee_tipe', 'admin_fee', 'metode_bagi_hasil', 'nilai_bagi_hasil'];

    public const ATRIBUT = [
        'ppn_persen' => 'PPN', 'pph_persen' => 'PPh 25', 'bhp_persen' => 'BHP', 'uso_persen' => 'USO',
        'admin_fee_tipe' => 'Tipe admin fee', 'admin_fee' => 'Admin fee', 'metode_bagi_hasil' => 'Metode bagi hasil', 'nilai_bagi_hasil' => 'Nilai bagi hasil',
    ];

    protected function casts(): array
    {
        return [
            'ppn_persen' => 'float',
            'pph_persen' => 'float',
            'bhp_persen' => 'float',
            'uso_persen' => 'float',
            'admin_fee' => 'float',
            'nilai_bagi_hasil' => 'float',
        ];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    /**
     * Aturan validasi tarif; nilai bertipe persentase dibatasi 0–100.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public static function aturan(array $input): array
    {
        $persen = ['required', 'numeric', 'between:0,100'];
        $nilai = fn (string $tipe) => ['required', 'numeric', ($input[$tipe] ?? null) === 'persentase' ? 'between:0,100' : 'min:0'];

        return [
            'ppn_persen' => $persen,
            'pph_persen' => $persen,
            'bhp_persen' => $persen,
            'uso_persen' => $persen,
            'admin_fee_tipe' => ['required', Rule::in(['persentase', 'nominal'])],
            'admin_fee' => $nilai('admin_fee_tipe'),
            'metode_bagi_hasil' => ['required', Rule::in(['persentase', 'nominal'])],
            'nilai_bagi_hasil' => $nilai('metode_bagi_hasil'),
        ];
    }

    /**
     * Tarif Januari–Desember satu cabang. Bulan tanpa baris memakai tarif tersimpan terakhir sebelumnya
     * (tersimpan = false); bila belum ada sama sekali, nilai tarifnya null.
     *
     * @return list<array<string, mixed>>
     */
    public static function setahun(int $idLokasi, int $tahun): array
    {
        $awal = sprintf('%04d-01-01', $tahun);
        $tersimpan = static::where('id_lokasi', $idLokasi)->whereBetween('periode', [$awal, sprintf('%04d-12-01', $tahun)])->get()
            ->keyBy(fn (self $p) => (int) substr($p->periode, 5, 2));
        $terakhir = static::where('id_lokasi', $idLokasi)->where('periode', '<', $awal)->latest('periode')->first();

        return array_map(function (int $bulan) use ($tahun, $tersimpan, &$terakhir) {
            $terakhir = $tersimpan->get($bulan) ?? $terakhir;
            $tarif = $terakhir?->only(self::TARIF) ?? array_fill_keys(self::TARIF, null);

            return [
                'bulan' => $bulan,
                'tahun' => $tahun,
                'periode' => sprintf('%04d-%02d-01', $tahun, $bulan),
                'tersimpan' => $tersimpan->has($bulan),
                ...$tarif,
            ];
        }, range(1, 12));
    }

    /**
     * Skema withdrawal: Omset (sudah termasuk PPN) − PPN − Admin Fee − Bagi Hasil (biaya bandwidth) − PPh = pendapatan reseller.
     * DPP = omset ÷ (1 + PPN). Admin fee persentase dari DPP, nominal per transaksi. Bagi hasil persentase dari omset, nominal per bulan.
     * PPh dari omset reseller (DPP − admin fee − bagi hasil). Nilai tidak dibulatkan; bulatkan saat ditampilkan.
     * Tanpa tarif (null) semua potongan 0.
     *
     * @return array{dpp: float, ppn: float, admin_fee: float, nominal_bagi_hasil: float, omset_reseller: float, pph: float, harus_setor: float, pendapatan_reseller: float}
     */
    public static function rincian(?self $tarif, float $pemasukan, int $jumlahTransaksi): array
    {
        $dpp = $pemasukan / (1 + ($tarif?->ppn_persen ?? 0) / 100);
        $adminFee = match ($tarif?->admin_fee_tipe) {
            'persentase' => $dpp * $tarif->admin_fee / 100,
            'nominal' => $tarif->admin_fee * $jumlahTransaksi,
            default => 0.0,
        };
        $bagiHasil = match ($tarif?->metode_bagi_hasil) {
            'persentase' => $pemasukan * $tarif->nilai_bagi_hasil / 100,
            'nominal' => $tarif->nilai_bagi_hasil,
            default => 0.0,
        };
        $omsetReseller = $dpp - $adminFee - $bagiHasil;
        $pph = max(0, $omsetReseller) * ($tarif?->pph_persen ?? 0) / 100;
        $ppn = $pemasukan - $dpp;

        return [
            'dpp' => $dpp,
            'ppn' => $ppn,
            'admin_fee' => $adminFee,
            'nominal_bagi_hasil' => $bagiHasil,
            'omset_reseller' => $omsetReseller,
            'pph' => $pph,
            'harus_setor' => $ppn + $adminFee + $bagiHasil + $pph,
            'pendapatan_reseller' => $omsetReseller - $pph,
        ];
    }

    /**
     * Tarif yang berlaku pada periode tertentu untuk beberapa cabang sekaligus, di-key id_lokasi.
     *
     * @param  list<int>  $idLokasi
     * @return Collection<int, self>
     */
    public static function berlaku(array $idLokasi, string $periode): Collection
    {
        // Urut naik lalu keyBy: baris terakhir (periode terbaru) per cabang yang dipakai
        return static::whereIn('id_lokasi', $idLokasi)->where('periode', '<=', $periode)->orderBy('periode')->get()->keyBy('id_lokasi');
    }
}
