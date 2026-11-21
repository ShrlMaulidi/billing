<?php

namespace App\Services\Midtrans;

use App\Models\Lokasi;
use App\Models\PaymentGateway;
use App\Models\TransaksiMidtrans;

/**
 * Akun Midtrans yang menerima pembayaran pelanggan suatu cabang:
 * - cabang Admin Full: payment gateway milik cabang itu sendiri (uang masuk ke mitra), tanpa cadangan;
 * - cabang lain: payment gateway perusahaan dari menu Payment Gateway, atau konfigurasi .env bila belum diatur di menu.
 * Hanya gateway aktif yang dipakai untuk transaksi baru; transaksi lama selalu diproses dengan akun tempat ia dibuat.
 */
final class AkunMidtrans
{
    public function __construct(public readonly ?PaymentGateway $gateway, public readonly MidtransClient $client) {}

    public static function untukLokasi(?Lokasi $lokasi): ?self
    {
        $adminFull = (bool) $lokasi?->admin_full;
        $gateway = PaymentGateway::where('provider', 'midtrans')->where('status', 'aktif')
            ->when($adminFull, fn ($q) => $q->where('id_lokasi', $lokasi->id), fn ($q) => $q->whereNull('id_lokasi'))
            ->first();
        if (! $gateway && ! $adminFull) {
            $env = new MidtransClient;

            return $env->aktif() ? new self(null, $env) : null;
        }
        $client = $gateway?->client();

        return $client?->aktif() ? new self($gateway, $client) : null;
    }

    /**
     * Klien untuk transaksi yang sudah dibuat (notifikasi, cek status, ganti metode), walau gatewaynya kini nonaktif.
     * Gateway yang sudah tidak ada menghasilkan klien tanpa kunci: tanda tangan & cek status gagal (aman, tidak tercatat lunas).
     */
    public static function untukTransaksi(TransaksiMidtrans $transaksi): MidtransClient
    {
        if ($transaksi->payment_gateway_id === null) {
            return new MidtransClient;
        }

        return $transaksi->gateway?->client() ?? new MidtransClient('', null, false);
    }
}
