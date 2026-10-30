<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rekening tujuan transfer (menu Kelola Data › Rekening). Tanpa cabang = berlaku untuk semua cabang. */
#[Table('rekening')]
#[Fillable(['nama_rekening', 'atas_nama', 'no_rekening', 'id_lokasi', 'tampil_invoice'])]
class Rekening extends Model
{
    protected function casts(): array
    {
        return [
            'tampil_invoice' => 'boolean',
        ];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class);
    }

    /** Label dropdown metode pembayaran, mis. "BCA · 1234567890 a.n. MSS". */
    public function label(): string
    {
        return "{$this->nama_rekening} · {$this->no_rekening} a.n. {$this->atas_nama}";
    }
}
