<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pesan dalam tiket: dari pelanggan, atau balasan staf. */
#[Table('tiket_pesan')]
#[Fillable(['tiket_id', 'user_id', 'dari_pelanggan', 'pesan'])]
class TiketPesan extends Model
{
    protected function casts(): array
    {
        return [
            'dari_pelanggan' => 'boolean',
        ];
    }

    public function tiket(): BelongsTo
    {
        return $this->belongsTo(Tiket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Data pesan untuk percakapan. Nama staf hanya nama tampilan (tanpa username/role). Muat relasi user lebih dulu.
     *
     * @return array<string, mixed>
     */
    public function toBaris(): array
    {
        return [
            'id' => $this->id,
            'dari_pelanggan' => $this->dari_pelanggan,
            'pengirim' => $this->dari_pelanggan ? ($this->user?->name ?? 'Pelanggan') : ($this->user?->name ?? 'Petugas').' · MSS',
            'pesan' => $this->pesan,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
