<?php

namespace App\Models;

use App\Support\TercatatAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tiket keluhan/permintaan pelanggan. Dibuat pelanggan dari halaman Akun Saya, dibalas & diubah statusnya oleh staf
 * di menu Tiket (cabang pelanggan). Tiket berstatus selesai tidak bisa dibalas pelanggan (buat tiket baru).
 */
#[Table('tiket')]
#[Fillable(['no_tiket', 'user_id', 'pelanggan_id', 'id_lokasi', 'kategori', 'judul', 'status', 'selesai_at'])]
class Tiket extends Model
{
    use TercatatAudit;

    /** Nama modul & jenis data di menu Logs (jejak audit). */
    public const MODUL_AUDIT = 'Tiket';

    public const JENIS_AUDIT = 'tiket';

    public const KATEGORI = ['gangguan' => 'Gangguan Internet', 'tagihan' => 'Tagihan & Pembayaran', 'paket' => 'Ganti / Upgrade Paket', 'lainnya' => 'Lainnya'];

    public const STATUS = ['baru' => 'Baru', 'diproses' => 'Diproses', 'selesai' => 'Selesai'];

    protected $attributes = ['status' => 'baru'];

    protected function casts(): array
    {
        return [
            'selesai_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function pesan(): HasMany
    {
        return $this->hasMany(TiketPesan::class)->orderBy('id');
    }

    public function labelAudit(): string
    {
        return $this->no_tiket ?? '#'.$this->getKey();
    }

    /** Nomor tiket mudah dibaca, mis. TKT2611-00012 (tahun-bulan + id). */
    public static function nomorUntuk(int $id): string
    {
        return sprintf('TKT%s-%05d', now()->format('ym'), $id);
    }

    /**
     * Ringkasan untuk daftar tiket (pelanggan & staf). Muat relasi pelanggan & lokasi lebih dulu.
     *
     * @return array<string, mixed>
     */
    public function toBaris(): array
    {
        return [
            'id' => $this->id,
            'no_tiket' => $this->no_tiket,
            'kategori' => $this->kategori,
            'kategori_label' => self::KATEGORI[$this->kategori] ?? $this->kategori,
            'judul' => $this->judul,
            'status' => $this->status,
            'pelanggan' => $this->pelanggan?->only(['id_pelanggan', 'nama']),
            'cabang' => $this->lokasi?->nama_lokasi,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
