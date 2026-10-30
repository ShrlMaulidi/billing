<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cabang / lokasi (menu Kelola Data › Cabang). */
#[Table('lokasi')]
#[Fillable(['kode_lokasi', 'nama_lokasi', 'alamat_lokasi', 'tlp_lokasi', 'tlp_owner', 'nama_mitra', 'group_wa', 'terima_cash', 'status'])]
class Lokasi extends Model
{
    protected function casts(): array
    {
        return [
            'terima_cash' => 'boolean',
        ];
    }

    /** Akun user yang ditugaskan ke cabang ini. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'id_lokasi');
    }

    public function area(): HasMany
    {
        return $this->hasMany(Area::class, 'id_lokasi');
    }

    public function pelanggan(): HasMany
    {
        return $this->hasMany(Pelanggan::class, 'id_lokasi');
    }

    public function routers(): HasMany
    {
        return $this->hasMany(Router::class, 'id_lokasi');
    }

    public function paket(): HasMany
    {
        return $this->hasMany(Paket::class, 'id_lokasi');
    }
}
