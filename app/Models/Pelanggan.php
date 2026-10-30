<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pelanggan. Akun koneksi di router: username_pppoe untuk PPPoE & Hotspot, ip_address untuk Static,
 * mac_address untuk IP Binding.
 */
#[Table('pelanggan')]
#[Fillable([
    'id_pelanggan', 'nama', 'tlp', 'nik', 'alamat', 'jns_tagihan', 'id_lokasi', 'id_area', 'id_paket', 'router_id', 'jns_koneksi',
    'username_pppoe', 'ip_address', 'mac_address', 'tgl_registrasi', 'tgl_jatuh_tempo', 'ppn', 'sales_id',
    'status', 'status_langganan', 'profile_asal', 'isolir_at',
])]
class Pelanggan extends Model
{
    public const KONEKSI = ['pppoe' => 'PPPoE', 'hotspot' => 'Hotspot', 'static' => 'Static', 'ip_binding' => 'IP Binding'];

    protected function casts(): array
    {
        return [
            'tgl_registrasi' => 'date:Y-m-d',
            'tgl_jatuh_tempo' => 'integer',
            'ppn' => 'boolean',
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

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'id_area');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    /** Identitas akun di router sesuai jenis koneksi (username, IP, atau MAC). */
    public function akunKoneksi(): ?string
    {
        return match ($this->jns_koneksi) {
            'static' => $this->ip_address,
            'ip_binding' => $this->mac_address,
            default => $this->username_pppoe,
        };
    }

    /**
     * Baris tabel pelanggan di halaman. Muat relasi lokasi & paket lebih dulu agar tidak N+1.
     *
     * @return array<string, mixed>
     */
    public function toBaris(): array
    {
        return [
            ...$this->toArray(),
            'cabang' => $this->lokasi?->nama_lokasi ?? '-',
            'paket' => $this->paket?->nama_paket ?? '-',
            'harga' => $this->paket?->harga,
        ];
    }
}
