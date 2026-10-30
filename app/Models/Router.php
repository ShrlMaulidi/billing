<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kode_router', 'nama_router', 'id_lokasi', 'mode_koneksi', 'host', 'tunnel_id', 'port_api', 'ssl', 'username', 'password',
    'profile_isolir', 'status_koneksi', 'identity', 'versi_routeros', 'last_error', 'last_checked_at',
])]
#[Hidden(['password'])]
class Router extends Model
{
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'ssl' => 'boolean',
            'port_api' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(Tunnel::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function pelanggan(): HasMany
    {
        return $this->hasMany(Pelanggan::class);
    }

    /** Alamat API yang dihubungi: host langsung (IP publik) atau IP router di dalam tunnel. */
    public function alamatApi(): ?string
    {
        return $this->mode_koneksi === 'tunnel' ? $this->tunnel?->ip_tunnel : $this->host;
    }
}
