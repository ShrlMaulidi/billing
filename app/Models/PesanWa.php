<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan pesan WhatsApp yang dikirim lewat WhatsApp cabang (terkirim / gagal). */
#[Table('pesan_wa')]
#[Fillable(['id_lokasi', 'pelanggan_id', 'user_id', 'jenis', 'periode', 'nomor', 'isi', 'status', 'keterangan', 'wa_id'])]
class PesanWa extends Model
{
    protected function casts(): array
    {
        return ['periode' => 'date:Y-m-d'];
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class);
    }

    public function pengirim(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
