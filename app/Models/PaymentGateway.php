<?php

namespace App\Models;

use App\Services\Midtrans\MidtransClient;
use App\Services\Pembayaran\MidtransPenyedia;
use App\Services\Pembayaran\Penyedia;
use App\Services\Pembayaran\TripayPenyedia;
use App\Services\Pembayaran\XenditPenyedia;
use App\Support\TercatatAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Akun payment gateway yang diatur dari menu Payment Gateway. Pemilik: perusahaan (id_lokasi null) atau cabang Admin Full.
 * Satu pemilik boleh punya beberapa gateway; yang ditandai Utama (satu per pemilik, dijamin kolom unik kunci_utama) dipakai
 * untuk transaksi baru: Utama perusahaan untuk semua cabang non Admin Full, Utama cabang untuk pelanggan cabang Admin Full itu.
 * Kredensial terenkripsi di database dan tersembunyi dari toArray()/JSON; di menu Logs selalu tampil "[disembunyikan]".
 *
 * @property array<string, string|null> $kredensial isian sesuai provider, lihat KREDENSIAL
 */
#[Table('payment_gateway')]
#[Fillable(['provider', 'nama', 'id_lokasi', 'produksi', 'kredensial', 'status', 'utama', 'diuji_at'])]
class PaymentGateway extends Model
{
    use TercatatAudit;

    /** Nama modul & jenis data di menu Logs (jejak audit). */
    public const MODUL_AUDIT = 'Payment Gateway';

    public const JENIS_AUDIT = 'payment gateway';

    /** Hasil uji koneksi & kunci pemilik bukan perubahan yang perlu diaudit. */
    public const KOLOM_TANPA_AUDIT = ['diuji_at', 'kunci_pemilik', 'kunci_utama'];

    /** Provider yang sudah terintegrasi penuh (buat transaksi, notifikasi, cek status). */
    public const PROVIDER = ['midtrans' => 'Midtrans', 'xendit' => 'Xendit', 'tripay' => 'Tripay'];

    /**
     * Isian kunci tiap provider: nama => [label, wajib]. Semua disimpan terenkripsi di kolom kredensial dan hanya
     * ditampilkan 4 karakter terakhirnya.
     *
     * @var array<string, array<string, array{0: string, 1: bool}>>
     */
    public const KREDENSIAL = [
        'midtrans' => ['server_key' => ['Server Key', true], 'signature_key' => ['Signature Secret Key', false]],
        'xendit' => ['secret_key' => ['Secret Key', true], 'callback_token' => ['Callback Verification Token', true]],
        'tripay' => ['merchant_code' => ['Kode Merchant', true], 'api_key' => ['API Key', true], 'private_key' => ['Private Key', true]],
    ];

    protected $hidden = ['kredensial'];

    protected function casts(): array
    {
        return [
            'produksi' => 'boolean',
            'utama' => 'boolean',
            'kredensial' => 'encrypted:array',
            'diuji_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $gateway) {
            $gateway->kunci_pemilik = self::kunciPemilik($gateway->id_lokasi);
            $gateway->kunci_utama = $gateway->utama ? $gateway->kunci_pemilik : null;
        });
    }

    /** Penanda pemilik: perusahaan atau satu cabang Admin Full. */
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

    /** Isian kunci yang dipakai uji koneksi & tempat menampilkan error kunci. */
    public const KOLOM_UJI = ['midtrans' => 'server_key', 'xendit' => 'secret_key', 'tripay' => 'api_key'];

    /** Klien provider untuk akun ini (kunci dari kolom kredensial terenkripsi). */
    public function penyedia(): Penyedia
    {
        $k = fn (string $nama) => (string) ($this->kredensial[$nama] ?? '');

        return match ($this->provider) {
            'xendit' => new XenditPenyedia($k('secret_key'), $k('callback_token'), $this->produksi),
            'tripay' => new TripayPenyedia($k('api_key'), $k('private_key'), $k('merchant_code'), $this->produksi),
            default => new MidtransPenyedia(new MidtransClient($k('server_key'), $this->kredensial['signature_key'] ?? null, $this->produksi)),
        };
    }

    /**
     * Kunci tersimpan yang aman ditampilkan (tersamar), sesuai isian provider.
     *
     * @return list<array{nama: string, label: string, nilai: ?string}>
     */
    public function kunciTersamar(): array
    {
        return collect(self::KREDENSIAL[$this->provider] ?? [])
            ->map(fn (array $isian, string $nama) => ['nama' => $nama, 'label' => $isian[0], 'nilai' => self::samarkan($this->kredensial[$nama] ?? null)])
            ->values()->all();
    }

    public function namaPemilik(): string
    {
        return $this->lokasi?->nama_lokasi ?? 'Perusahaan';
    }

    public function labelAudit(): string
    {
        return ($this->nama ?: (self::PROVIDER[$this->provider] ?? $this->provider)).' · '.$this->namaPemilik();
    }

    /** Gateway Utama aktif milik pemilik ini (perusahaan bila $idLokasi null). */
    public static function utamaUntuk(?int $idLokasi): ?self
    {
        return self::where('kunci_utama', self::kunciPemilik($idLokasi))->where('status', 'aktif')->first();
    }
}
