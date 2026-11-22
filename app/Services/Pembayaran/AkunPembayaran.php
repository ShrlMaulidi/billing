<?php

namespace App\Services\Pembayaran;

use App\Models\Lokasi;
use App\Models\PaymentGateway;
use App\Models\TransaksiMidtrans;
use App\Services\Midtrans\MidtransClient;
use Illuminate\Http\Request;

/**
 * Akun payment gateway yang menerima pembayaran pelanggan suatu cabang:
 * - cabang Admin Full: gateway Utama milik cabang itu sendiri (uang masuk ke mitra), tanpa cadangan;
 * - cabang lain: gateway Utama perusahaan dari menu Payment Gateway, atau Midtrans dari .env bila belum ada.
 * Provider bisa Midtrans, Xendit, atau Tripay. Transaksi lama selalu diproses dengan akun tempat ia dibuat.
 */
final class AkunPembayaran
{
    public function __construct(public readonly ?PaymentGateway $gateway, public readonly Penyedia $penyedia) {}

    public static function untukLokasi(?Lokasi $lokasi): ?self
    {
        $adminFull = (bool) $lokasi?->admin_full;
        $gateway = PaymentGateway::utamaUntuk($adminFull ? $lokasi->id : null);
        if (! $gateway && ! $adminFull) {
            $env = new MidtransPenyedia(new MidtransClient);

            return $env->aktif() ? new self(null, $env) : null;
        }
        $penyedia = $gateway?->penyedia();

        return $penyedia?->aktif() ? new self($gateway, $penyedia) : null;
    }

    /**
     * Provider untuk transaksi yang sudah dibuat (notifikasi, cek status, ganti metode), walau gatewaynya kini nonaktif.
     * Transaksi tanpa gateway dibuat dengan Midtrans .env; gateway yang sudah tidak ada menghasilkan Midtrans tanpa kunci
     * (tanda tangan & cek status gagal: aman, tidak tercatat lunas).
     */
    public static function untukTransaksi(TransaksiMidtrans $transaksi): Penyedia
    {
        if ($transaksi->payment_gateway_id === null) {
            return new MidtransPenyedia(new MidtransClient);
        }

        return $transaksi->gateway?->penyedia() ?? new MidtransPenyedia(new MidtransClient('', null, false));
    }

    /** Order ID billing pada isi notifikasi tiap provider (nama kolomnya berbeda); null bila tidak ada / bukan teks. */
    public static function orderIdNotifikasi(string $provider, Request $request): ?string
    {
        $nilai = $request->input(match ($provider) {
            'xendit' => 'external_id',
            'tripay' => 'merchant_ref',
            default => 'order_id',
        });

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }
}
