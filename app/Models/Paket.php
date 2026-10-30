<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('paket')]
#[Fillable([
    'nama_paket', 'keterangan', 'harga', 'jenis', 'jns_koneksi', 'id_lokasi', 'filter_lokasi', 'router_id', 'profile_mikrotik',
    'rate_limit', 'local_address', 'buat_profile', 'buat_pool', 'nama_pool', 'range_pool', 'tampil_registrasi', 'synced_at', 'sync_error',
])]
class Paket extends Model
{
    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'filter_lokasi' => 'boolean',
            'buat_profile' => 'boolean',
            'buat_pool' => 'boolean',
            'tampil_registrasi' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function pelanggan(): HasMany
    {
        return $this->hasMany(Pelanggan::class, 'id_paket');
    }
}
