<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan penarikan saldo mitra dari saldo satu periode (menu Transaksi › Penarikan Saldo Mitra).
 * Alur: pending → disetujui (Super Admin sudah transfer & mengunggah bukti, saldo berkurang), atau pending → ditolak.
 * Kolom periode_sumber sengaja tanpa cast date: disimpan & dibandingkan sebagai teks Y-m-d seperti pembayaran.periode.
 */
#[Table('penarikan_saldo')]
#[Fillable([
    'id_lokasi', 'user_id', 'nama_request', 'periode_sumber', 'nominal', 'keterangan', 'bank_penerima', 'no_rekening_penerima', 'atas_nama_penerima',
    'status', 'diproses_oleh', 'diproses_at', 'catatan', 'bukti_transfer',
])]
class PenarikanSaldo extends Model
{
    public const STATUS = ['pending', 'disetujui', 'ditolak'];

    /** Status yang sudah mengurangi saldo mitra. */
    public const MENGURANGI_SALDO = ['disetujui'];

    /** Folder bukti transfer di disk privat "local"; dibuka lewat route yang memeriksa hak akses cabang. */
    public const FOLDER_BUKTI = 'bukti-penarikan';

    protected function casts(): array
    {
        return [
            'nominal' => 'integer',
            'diproses_at' => 'datetime',
        ];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function pemohon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pemroses(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diproses_oleh');
    }
}
