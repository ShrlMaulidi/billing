<?php

namespace App\Services\Midtrans;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Klien API Midtrans (Snap & cek status) tanpa SDK untuk satu akun Midtrans: kunci dari menu Payment Gateway
 * (PaymentGateway::client()), atau dari config/.env bila dibuat tanpa argumen.
 * Server Key tidak pernah dikirim ke browser atau dicatat di log.
 */
class MidtransClient
{
    private string $serverKey;

    private ?string $signatureKey;

    private bool $produksi;

    public function __construct(?string $serverKey = null, ?string $signatureKey = null, ?bool $produksi = null)
    {
        $dariConfig = $serverKey === null;
        $this->serverKey = $dariConfig ? (string) config('services.midtrans.server_key') : $serverKey;
        $this->signatureKey = $dariConfig ? config('services.midtrans.signature_key') : $signatureKey;
        $this->produksi = $produksi ?? (bool) config('services.midtrans.production');
    }

    /** Kunci Sandbox diawali "SB-", kunci Production tidak; kunci yang tertukar membuat setiap transaksi ditolak Midtrans. */
    public static function kunciSesuaiMode(string $kunci, bool $produksi): bool
    {
        return $kunci !== '' && str_starts_with($kunci, 'SB-') !== $produksi;
    }

    public function sesuaiMode(): bool
    {
        return self::kunciSesuaiMode($this->serverKey, $this->produksi);
    }

    /** Pembayaran online aktif bila Server Key terisi dan cocok dengan modenya; bila tidak, tombol bayar disembunyikan. */
    public function aktif(): bool
    {
        if ($this->serverKey === '') {
            return false;
        }
        if (! self::kunciSesuaiMode($this->serverKey, $this->produksi)) {
            Log::error('Midtrans nonaktif: Server Key tidak cocok dengan mode Sandbox/Production (kunci Sandbox diawali SB-, kunci Production tidak).');

            return false;
        }

        return true;
    }

    /**
     * Uji Server Key ke Midtrans dengan menanyakan status order yang pasti tidak ada: "tidak ditemukan" berarti kunci diterima,
     * 401/403 berarti kunci ditolak. Gangguan jaringan atau server Midtrans melempar exception.
     */
    public function ujiKoneksi(): bool
    {
        $respons = $this->http()->get($this->urlApi().'/v2/'.rawurlencode('UJI-'.Str::lower(Str::random(20))).'/status');
        $kode = (string) $respons->json('status_code');
        if (in_array($respons->status(), [401, 403], true) || in_array($kode, ['401', '403'], true)) {
            return false;
        }
        if ($respons->status() === 404 || $kode === '404') {
            return true;
        }
        $respons->throw();

        throw new RuntimeException('Jawaban Midtrans tidak dikenali saat uji koneksi: '.$respons->status().' '.$kode);
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
     * pada dashboard Midtrans baru. Keduanya rahasia; status tetap dicek ulang ke API setelahnya.
     */
    public function tandaTanganSah(array $notifikasi): bool
    {
        $kolom = ['order_id', 'status_code', 'gross_amount', 'signature_key'];
        // Hanya teks/angka: array atau objek di payload palsu langsung ditolak, bukan memicu error
        if (! $this->aktif() || collect($kolom)->contains(fn (string $k) => ! isset($notifikasi[$k]) || ! is_scalar($notifikasi[$k]))) {
            return false;
        }
        $data = $notifikasi['order_id'].$notifikasi['status_code'].$notifikasi['gross_amount'];
        $kunci = array_filter([$this->serverKey, $this->signatureKey], 'filled');

        return collect($kunci)->contains(fn (string $k) => hash_equals(hash('sha512', $data.$k), (string) $notifikasi['signature_key']));
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->serverKey, '')
            ->acceptJson()->asJson()->timeout(config('services.midtrans.timeout', 15));
    }

    private function urlSnap(): string
    {
        return $this->produksi ? 'https://app.midtrans.com/snap/v1/transactions' : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    private function urlApi(): string
    {
        return $this->produksi ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }
}
