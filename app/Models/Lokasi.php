<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabang / lokasi (menu Kelola Data › Cabang).
 * admin_full: mitra memakai payment gateway sendiri (uang online masuk ke mitra, lalu disetor sesuai kewajiban);
 * bila false, cabang ikut payment gateway perusahaan.
 * tipe: reseller (mitra menjual kembali ke end user) atau retail (produk ISP sendiri / Shafa.net); ditambahkan lewat menu Data Klien.
 */
#[Table('lokasi')]
#[Fillable(['tipe', 'kode_lokasi', 'nama_lokasi', 'alamat_lokasi', 'tlp_lokasi', 'tlp_owner', 'nama_mitra', 'group_wa', 'terima_cash', 'admin_full', 'status'])]
class Lokasi extends Model
{
    /** @var array<string, string> */
    public const TIPE = ['reseller' => 'Mitra Reseller', 'retail' => 'Retail / Shafa.net'];

    protected function casts(): array
    {
        return [
            'terima_cash' => 'boolean',
            'admin_full' => 'boolean',
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

    public function rekening(): HasMany
    {
        return $this->hasMany(Rekening::class, 'id_lokasi');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'id_lokasi');
    }

    /** Tarif pajak & bagi hasil per bulan. */
    public function pajak(): HasMany
    {
        return $this->hasMany(PajakCabang::class, 'id_lokasi');
    }
}
