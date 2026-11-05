<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setoran mitra ke perusahaan (menu Transaksi › Pembayaran Masuk, tipe Mitra Reseller / Shafa.net), diinput Super Admin.
 * Setiap setoran langsung menambah saldo mitra pada periode kewajibannya (kolom Sudah Setor di rekap cabang).
 * Kolom periode sengaja tanpa cast date: disimpan & dibandingkan sebagai teks Y-m-d seperti pembayaran.periode.
 */
#[Table('setoran_mitra')]
#[Fillable(['id_lokasi', 'periode', 'nominal', 'metode', 'rekening_id', 'bukti_setor', 'keterangan', 'user_id'])]
class SetoranMitra extends Model
{
    /** Folder bukti setor di disk privat "local"; dibuka lewat route yang memeriksa hak akses cabang. */
    public const FOLDER_BUKTI = 'bukti-setoran';

    protected function casts(): array
    {
        return [
            'nominal' => 'integer',
        ];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
