<?php

namespace App\Services\Pembayaran;

use App\Models\Pelanggan;
use App\Models\TransaksiMidtrans;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Xendit Invoice: pelanggan memilih metode (VA, QRIS, e-wallet, gerai) di halaman invoice Xendit.
 * - Secret Key: Basic Auth; kunci test diawali "xnd_development_", kunci live "xnd_production_" (satu URL API untuk keduanya).
 * - Notifikasi: Callback URL "Invoice paid" diisi di dashboard Xendit; keabsahannya lewat header x-callback-token yang harus
 *   sama dengan Callback Verification Token akun ini. Status tetap dicek ulang ke API.
 */
final class XenditPenyedia implements Penyedia
{
    private const URL = 'https://api.xendit.co';

    public function __construct(
        private readonly string $secretKey,
        private readonly string $callbackToken,
        private readonly bool $produksi,
    ) {}

    public function nama(): string
    {
        return 'Xendit';
    }

    public function aktif(): bool
    {
        return $this->secretKey !== '' && $this->callbackToken !== '' && $this->sesuaiMode();
    }

    public function sesuaiMode(): bool
    {
        return str_starts_with($this->secretKey, $this->produksi ? 'xnd_production_' : 'xnd_development_');
    }

    /** Cek saldo: 200 = kunci diterima, 401/403 = ditolak. */
    public function ujiKoneksi(): bool
    {
        $respons = $this->http()->get(self::URL.'/balance');
        if (in_array($respons->status(), [401, 403], true)) {
            return false;
        }
        $respons->throw();

        return true;
    }

    public function butuhKanal(): bool
    {
        return false;
    }

    public function daftarKanal(int $nominal): array
    {
        return [];
    }

    public function buatTransaksi(TransaksiMidtrans $transaksi, array $tagihan, Pelanggan $pelanggan, ?string $kanal, int $jam): array
    {
        $kembali = route('midtrans.selesai', ['order_id' => $transaksi->order_id]);
        $invoice = $this->http()->post(self::URL.'/v2/invoices', [
            'external_id' => $transaksi->order_id,
            'amount' => $transaksi->gross_amount,
            'currency' => 'IDR',
            'description' => Str::limit('Tagihan internet '.($tagihan['nama_paket'] ?? '').' '.Carbon::parse($tagihan['periode'])->locale('id')->translatedFormat('F Y'), 250, ''),
            'invoice_duration' => $jam * 3600,
            'customer' => ['given_names' => Str::limit($pelanggan->nama, 50, '')],
            'items' => [['name' => Str::limit('Internet '.($tagihan['nama_paket'] ?? ''), 50, ''), 'quantity' => 1, 'price' => $transaksi->gross_amount]],
            'success_redirect_url' => $kembali,
            'failure_redirect_url' => $kembali,
        ])->throw()->json();

        return ['redirect_url' => (string) ($invoice['invoice_url'] ?? ''), 'referensi' => $invoice['id'] ?? null, 'kanal' => null];
    }

    public function status(TransaksiMidtrans $transaksi): ?HasilStatus
    {
        if (! $transaksi->transaction_id) {
            return null;
        }
        $invoice = $this->http()->get(self::URL.'/v2/invoices/'.rawurlencode($transaksi->transaction_id))->throw()->json();
        if (($invoice['external_id'] ?? null) !== $transaksi->order_id) {
            return null;
        }
        $asli = (string) ($invoice['status'] ?? '');

        return new HasilStatus(
            status: match ($asli) {
                'PAID', 'SETTLED' => 'lunas',
                'PENDING' => 'pending',
                'EXPIRED' => 'expire',
                default => 'tidak_dikenal',
            },
            nominal: (int) round((float) ($invoice['paid_amount'] ?? $invoice['amount'] ?? 0)),
            kanal: $invoice['payment_channel'] ?? $invoice['payment_method'] ?? null,
            referensi: $invoice['id'] ?? null,
            dibayarAt: isset($invoice['paid_at']) ? Carbon::parse($invoice['paid_at'])->timezone(config('app.timezone')) : null,
            statusAsli: $asli,
        );
    }

    public function hentikan(TransaksiMidtrans $transaksi): string
    {
        if (! $transaksi->transaction_id) {
            return 'belum_ada';
        }
        $respons = $this->http()->post(self::URL.'/invoices/'.rawurlencode($transaksi->transaction_id).'/expire!');
        $respons->throw();
        if ($respons->json('status') !== 'EXPIRED') {
            throw new RuntimeException("Xendit menolak menghentikan invoice {$transaksi->order_id}: ".$respons->json('status'));
        }

        return 'kedaluwarsa';
    }

    public function notifikasiSah(Request $request): bool
    {
        $token = $request->header('x-callback-token');

        return $this->callbackToken !== '' && is_string($token) && hash_equals($this->callbackToken, $token);
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->secretKey, '')->acceptJson()->asJson()->timeout(config('services.pembayaran.timeout', 15));
    }
}
