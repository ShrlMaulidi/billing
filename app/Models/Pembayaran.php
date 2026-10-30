<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran tagihan satu periode (bulan) pelanggan.
 * Kolom periode sengaja tanpa cast date: disimpan & dibandingkan sebagai teks Y-m-d (tanpa jam) di MySQL maupun SQLite.
 */
#[Table('pembayaran')]
#[Fillable([
    'no_invoice', 'pelanggan_id', 'id_lokasi', 'periode', 'nama_pelanggan', 'nama_paket', 'harga', 'diskon_tipe', 'diskon_nilai', 'diskon',
    'jml_bayar', 'metode', 'rekening_id', 'user_id', 'paid_at',
])]
class Pembayaran extends Model
{
    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'diskon_nilai' => 'float',
            'diskon' => 'integer',
            'jml_bayar' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class);
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Rentang tahun yang bisa dipilih saat input pembayaran, dihitung dari tahun berjalan. */
    public const TAHUN_MUNDUR = 5;

    public const TAHUN_MAJU = 5;

    /** @return list<int> */
    public static function tahunBayar(): array
    {
        return range(today()->year - self::TAHUN_MUNDUR, today()->year + self::TAHUN_MAJU);
    }

    /** Potongan dalam Rupiah (dibulatkan) dari diskon nominal atau persentase. */
    public static function hitungDiskon(int $harga, ?string $tipe, float $nilai): int
    {
        return match ($tipe) {
            'persentase' => (int) round($harga * $nilai / 100),
            'nominal' => (int) round($nilai),
            default => 0,
        };
    }
}
