<?php

namespace App\Services\Pembayaran;

use Illuminate\Support\Carbon;

/**
 * Status transaksi dari API provider yang sudah diseragamkan.
 * status: lunas | pending | expire | cancel | deny | failure | ditarik (refund/chargeback, hanya berarti bila sebelumnya lunas)
 * | tidak_dikenal (status baru dari provider yang belum dipetakan: status transaksi tidak diubah).
 */
final class HasilStatus
{
    public function __construct(
        public readonly string $status,
        /** Nominal tagihan yang dibayar (tanpa biaya yang dibebankan ke pelanggan), untuk dicocokkan dengan tagihan. */
        public readonly ?int $nominal = null,
        public readonly ?string $kanal = null,
        public readonly ?string $referensi = null,
        public readonly ?Carbon $dibayarAt = null,
        /** Status asli dari provider, untuk catatan. */
        public readonly ?string $statusAsli = null,
    ) {}
}
