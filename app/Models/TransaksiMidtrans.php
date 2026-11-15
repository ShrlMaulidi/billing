<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transaksi pembayaran online Midtrans untuk satu tagihan pelanggan (lihat App\Http\Controllers\MidtransController). */
#[Table('transaksi_midtrans')]
#[Fillable(['order_id', 'pelanggan_id', 'periode', 'gross_amount', 'status', 'payment_type', 'transaction_id', 'redirect_url', 'kedaluwarsa_at', 'dibayar_at', 'pembayaran_id', 'catatan'])]
class TransaksiMidtrans extends Model
{
    /** Status yang berarti uang sudah diterima Midtrans. */
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

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(Pembayaran::class);
    }

    /** Transaksi pending yang link pembayarannya masih bisa dipakai ulang. */
    public function masihBerlaku(): bool
    {
        return $this->status === 'pending' && $this->redirect_url && $this->kedaluwarsa_at?->isFuture();
    }
}
