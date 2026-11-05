<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran masuk dari pelanggan korporat & instansi pemerintah (menu Transaksi › Pembayaran Masuk), diinput user pusat.
 * Kolom periode sengaja tanpa cast date: disimpan & dibandingkan sebagai teks Y-m-d seperti setoran_mitra.periode.
 */
#[Table('pembayaran_instansi')]
#[Fillable(['pelanggan_instansi_id', 'periode', 'nominal', 'ppn_persen', 'ppn', 'total', 'user_id'])]
class PembayaranInstansi extends Model
{
    /** Tarif PPN bawaan form Pembayaran Masuk & perkiraan tagihan periode yang belum dibayar. */
    public const PPN_BAWAAN = 11;

    protected function casts(): array
    {
        return [
            'nominal' => 'integer',
            'ppn_persen' => 'float',
            'ppn' => 'integer',
            'total' => 'integer',
        ];
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(PelangganInstansi::class, 'pelanggan_instansi_id');
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
