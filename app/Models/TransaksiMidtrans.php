<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaksi pembayaran online untuk satu tagihan pelanggan (lihat App\Http\Controllers\BayarOnlineController). Nama tabel masih
 * transaksi_midtrans karena awalnya hanya Midtrans; kini juga Xendit & Tripay (provider dari gateway tempat transaksi dibuat).
 * order_id = nomor billing, transaction_id = nomor di provider (Xendit invoice id / Tripay reference), payment_type = kanal bayar.
 */
#[Table('transaksi_midtrans')]
#[Fillable(['order_id', 'pelanggan_id', 'payment_gateway_id', 'periode', 'gross_amount', 'status', 'payment_type', 'transaction_id', 'redirect_url', 'kedaluwarsa_at', 'dibayar_at', 'pembayaran_id', 'catatan'])]
class TransaksiMidtrans extends Model
{
    /**
     * Status yang berarti uang sudah diterima Midtrans. Status lain: pending, expire, cancel, deny, failure,
     * selisih (nominal beda, periksa manual), ditarik (refund/chargeback setelah lunas, tinjau manual).
     */
    public const LUNAS = ['lunas', 'lunas_ganda'];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'integer',
            'kedaluwarsa_at' => 'datetime',
            'dibayar_at' => 'datetime',
        ];
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class);
    }

    /** Akun payment gateway tempat transaksi dibuat; null = Midtrans dari konfigurasi .env. */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(Pembayaran::class);
    }

    /** Provider tempat transaksi dibuat: midtrans | xendit | tripay. */
    public function provider(): string
    {
        return $this->gateway?->provider ?? 'midtrans';
    }

    /** Nama kanal bayar yang mudah dipahami pelanggan, mis. "QRIS", "BRI Virtual Account". */
    public static function namaKanal(string $provider, ?string $kode): ?string
    {
        if (! $kode) {
            return null;
        }
        if ($provider === 'midtrans') {
            return Pembayaran::KANAL_MIDTRANS[$kode] ?? str($kode)->replace('_', ' ')->title()->toString();
        }

        return self::KANAL_LAIN[strtoupper($kode)] ?? str($kode)->replace('_', ' ')->toString();
    }

    /** Kode kanal Xendit & Tripay yang umum → nama tampilan; kode lain ditampilkan apa adanya. */
    private const KANAL_LAIN = [
        'QRIS' => 'QRIS', 'QRISC' => 'QRIS', 'QRIS2' => 'QRIS', 'BRIVA' => 'BRI Virtual Account', 'BNIVA' => 'BNI Virtual Account', 'BCAVA' => 'BCA Virtual Account',
        'MANDIRIVA' => 'Mandiri Virtual Account', 'PERMATAVA' => 'Permata Virtual Account', 'BSIVA' => 'BSI Virtual Account', 'CIMBVA' => 'CIMB Virtual Account',
        'ALFAMART' => 'Alfamart', 'INDOMARET' => 'Indomaret', 'OVO' => 'OVO', 'DANA' => 'DANA', 'SHOPEEPAY' => 'ShopeePay', 'GOPAY' => 'GoPay',
        'LINKAJA' => 'LinkAja', 'BCA' => 'BCA Virtual Account', 'BNI' => 'BNI Virtual Account', 'BRI' => 'BRI Virtual Account', 'MANDIRI' => 'Mandiri Virtual Account',
        'PERMATA' => 'Permata Virtual Account', 'BSI' => 'BSI Virtual Account', 'CREDIT_CARD' => 'Kartu Kredit',
    ];

    /** Transaksi pending yang link pembayarannya masih bisa dipakai ulang. */
    public function masihBerlaku(): bool
    {
        return $this->status === 'pending' && $this->redirect_url && $this->kedaluwarsa_at?->isFuture();
    }
}
