<?php

namespace App\Models;

use App\Support\TercatatAudit;
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
    use TercatatAudit;

    /** Nama modul & jenis data di menu Logs (jejak audit). */
    public const MODUL_AUDIT = 'Paket';

    public const JENIS_AUDIT = 'paket';

    /** Jenis koneksi yang memakai profile MikroTik (PPP Profile / Hotspot User Profile). */
    public const KONEKSI_PROFILE = ['pppoe', 'hotspot'];

    public function pakaiProfile(): bool
    {
        return in_array($this->jns_koneksi, self::KONEKSI_PROFILE, true);
    }

    public function labelKoneksi(): string
    {
        return Pelanggan::KONEKSI[$this->jns_koneksi] ?? $this->jns_koneksi;
    }

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

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function pelanggan(): HasMany
    {
        return $this->hasMany(Pelanggan::class, 'id_paket');
    }
}
