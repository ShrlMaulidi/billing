<?php

namespace App\Services\Midtrans;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Klien API Midtrans (Snap & cek status) tanpa SDK: memakai HTTP client Laravel dengan Server Key dari config.
 * Server Key tidak pernah dikirim ke browser atau dicatat di log.
 */
class MidtransClient
{
    /**
     * Pembayaran online aktif bila Server Key terisi dan cocok dengan modenya: kunci Sandbox diawali "SB-",
     * kunci Production tidak. Kunci yang tertukar membuat setiap transaksi ditolak Midtrans, jadi tombol bayar disembunyikan.
     */
    public function aktif(): bool
    {
        $kunci = (string) config('services.midtrans.server_key');
        if ($kunci === '') {
            return false;
        }
        if (str_starts_with($kunci, 'SB-') === (bool) config('services.midtrans.production')) {
            Log::error('Midtrans nonaktif: MIDTRANS_SERVER_KEY tidak cocok dengan MIDTRANS_PRODUCTION (kunci Sandbox diawali SB-, kunci Production tidak).');

            return false;
        }

        return true;
    }

    /**
     * Buat transaksi Snap. Notifikasi untuk transaksi ini diarahkan ke webhook billing lewat X-Override-Notification,
     * sehingga Payment Notification URL di dashboard Midtrans (dipakai website lain) tidak perlu diubah.
     *
     * @param  array<string, mixed>  $payload
     * @return array{token: string, redirect_url: string}
     */
    public function buatTransaksi(array $payload, string $urlNotifikasi): array
    {
        return $this->http()->withHeaders(['X-Override-Notification' => $urlNotifikasi])
            ->post($this->urlSnap(), $payload)->throw()->json();
    }

    /**
     * Status terbaru langsung dari Midtrans (sumber kebenaran, bukan isi notifikasi).
     *
     * @return array<string, mixed>
     */
    public function status(string $orderId): array
    {
        return $this->http()->get($this->urlApi().'/v2/'.rawurlencode($orderId).'/status')->throw()->json();
    }

    /**
     * Hentikan transaksi pending (pelanggan ingin ganti metode pembayaran) agar kode QRIS / nomor VA lama tidak bisa dibayar lagi.
     * Butuh izin "Expire transaction: Write" pada Server Key. Mengembalikan 'belum_ada' bila pelanggan belum memilih
     * metode apa pun (belum ada transaksi di Midtrans); gagal atau ditolak Midtrans melempar exception.
     *
     * @return 'kedaluwarsa'|'belum_ada'
     */
    public function kedaluwarsakan(string $orderId): string
    {
        $respons = $this->http()->post($this->urlApi().'/v2/'.rawurlencode($orderId).'/expire');
        if ($respons->status() === 404 || (string) $respons->json('status_code') === '404') {
            return 'belum_ada';
        }
        $respons->throw();
        if ($respons->json('transaction_status') !== 'expire') {
            throw new RuntimeException("Midtrans menolak expire {$orderId}: ".$respons->json('status_code').' '.$respons->json('status_message'));
        }

        return 'kedaluwarsa';
    }

    /**
     * signature_key notifikasi = SHA512(order_id + status_code + gross_amount + kunci). Kunci = Server Key, atau Signature Secret Key
     * pada dashboard Midtrans baru (MIDTRANS_SIGNATURE_KEY). Keduanya rahasia; status tetap dicek ulang ke API setelahnya.
     */
    public function tandaTanganSah(array $notifikasi): bool
    {
        $kolom = ['order_id', 'status_code', 'gross_amount', 'signature_key'];
        // Hanya teks/angka: array atau objek di payload palsu langsung ditolak, bukan memicu error
        if (! $this->aktif() || collect($kolom)->contains(fn (string $k) => ! isset($notifikasi[$k]) || ! is_scalar($notifikasi[$k]))) {
            return false;
        }
        $data = $notifikasi['order_id'].$notifikasi['status_code'].$notifikasi['gross_amount'];
        $kunci = array_filter([config('services.midtrans.server_key'), config('services.midtrans.signature_key')], 'filled');

        return collect($kunci)->contains(fn (string $k) => hash_equals(hash('sha512', $data.$k), (string) $notifikasi['signature_key']));
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->acceptJson()->asJson()->timeout(config('services.midtrans.timeout', 15));
    }

    private function urlSnap(): string
    {
        return config('services.midtrans.production') ? 'https://app.midtrans.com/snap/v1/transactions' : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    private function urlApi(): string
    {
        return config('services.midtrans.production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }
}
