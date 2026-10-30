<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('pelanggan')]
#[Fillable([
    'id_pelanggan', 'nama', 'tlp', 'alamat', 'id_lokasi', 'id_paket', 'router_id', 'username_pppoe',
    'tgl_registrasi', 'tgl_jatuh_tempo', 'status', 'status_langganan', 'profile_asal', 'isolir_at',
])]
class Pelanggan extends Model
{
    protected function casts(): array
    {
        return [
            'tgl_registrasi' => 'date:Y-m-d',
            'tgl_jatuh_tempo' => 'integer',
            'isolir_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function paket(): BelongsTo
    {
        return $this->belongsTo(Paket::class, 'id_paket');
    }
}
