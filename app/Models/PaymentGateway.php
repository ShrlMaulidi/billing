<?php

namespace App\Models;

use App\Services\Midtrans\MidtransClient;
use App\Support\TercatatAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Akun payment gateway yang diatur dari menu Payment Gateway. Pemilik: perusahaan (id_lokasi null) atau cabang Admin Full.
 * Kredensial terenkripsi di database dan tersembunyi dari toArray()/JSON; di menu Logs selalu tampil "[disembunyikan]".
 *
 * @property array{server_key?: string, signature_key?: string|null} $kredensial
 */
#[Table('payment_gateway')]
#[Fillable(['provider', 'id_lokasi', 'produksi', 'kredensial', 'status', 'diuji_at'])]
class PaymentGateway extends Model
{
    use TercatatAudit;

    /** Nama modul & jenis data di menu Logs (jejak audit). */
    public const MODUL_AUDIT = 'Payment Gateway';

    public const JENIS_AUDIT = 'payment gateway';

    /** Hasil uji koneksi & kunci pemilik bukan perubahan yang perlu diaudit. */
    public const KOLOM_TANPA_AUDIT = ['diuji_at', 'kunci_pemilik'];

    /** Provider yang sudah terintegrasi penuh (buat transaksi, notifikasi, cek status). */
    public const PROVIDER = ['midtrans' => 'Midtrans'];

    protected $hidden = ['kredensial'];

    protected function casts(): array
    {
        return [
            'produksi' => 'boolean',
            'kredensial' => 'encrypted:array',
            'diuji_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $gateway) => $gateway->kunci_pemilik = self::kunciPemilik($gateway->id_lokasi));
    }

    /** Kunci unik per pemilik: satu gateway untuk perusahaan dan satu untuk tiap cabang Admin Full. */
    public static function kunciPemilik(?int $idLokasi): string
    {
        return $idLokasi ? "lokasi:{$idLokasi}" : 'perusahaan';
    }

    /** Hanya 4 karakter terakhir yang ditampilkan, cukup untuk mengenali kunci tanpa membukanya. */
    public static function samarkan(?string $kunci): ?string
    {
        return filled($kunci) ? '••••'.substr($kunci, -4) : null;
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(TransaksiMidtrans::class);
    }

    public function client(): MidtransClient
    {
        return new MidtransClient((string) ($this->kredensial['server_key'] ?? ''), $this->kredensial['signature_key'] ?? null, $this->produksi);
    }

    public function namaPemilik(): string
    {
        return $this->lokasi?->nama_lokasi ?? 'Perusahaan';
    }

    public function labelAudit(): string
    {
        return (self::PROVIDER[$this->provider] ?? $this->provider).' · '.$this->namaPemilik();
    }
}
