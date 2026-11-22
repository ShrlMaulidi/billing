<?php

namespace App\Services\Pembayaran;

use App\Models\Pelanggan;
use App\Models\TransaksiMidtrans;
use App\Support\NomorHp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tripay closed payment: metode bayar (QRIS, VA, gerai, e-wallet) dipilih pelanggan di aplikasi, lalu diarahkan ke checkout Tripay.
 * - API Key: Authorization Bearer; Sandbox https://tripay.co.id/api-sandbox, Production https://tripay.co.id/api.
 * - Signature transaksi: HMAC-SHA256(kode merchant + merchant_ref + amount, Private Key).
 * - Notifikasi: callback_url dikirim per transaksi; X-Callback-Signature = HMAC-SHA256(isi mentah callback, Private Key).
 * - Tidak ada API pembatalan: saat ganti metode, kode lama tetap bisa dibayar sampai kedaluwarsa (bila terbayar ganda, ditandai
 *   lunas_ganda untuk dikembalikan manual).
 */
final class TripayPenyedia implements Penyedia
{
    /** Daftar metode bayar disimpan sebentar agar tidak memanggil API setiap kali tombol bayar diklik. */
    private const MENIT_CACHE_KANAL = 10;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $privateKey,
        private readonly string $kodeMerchant,
        private readonly bool $produksi,
    ) {}

    public function nama(): string
    {
        return 'Tripay';
    }

    public function aktif(): bool
    {
        return $this->apiKey !== '' && $this->privateKey !== '' && $this->kodeMerchant !== '';
    }

    /** Tripay tidak membedakan kunci lewat awalan; kunci yang salah mode ditolak saat uji koneksi. */
    public function sesuaiMode(): bool
    {
        return true;
    }

    public function ujiKoneksi(): bool
    {
        $respons = $this->http()->get($this->url('/merchant/payment-channel'));
        if (in_array($respons->status(), [401, 403], true) || $respons->json('success') === false) {
            return false;
        }
        $respons->throw();

        return $respons->json('success') === true;
    }

    public function butuhKanal(): bool
    {
        return true;
    }

    public function daftarKanal(int $nominal): array
    {
        $semua = Cache::remember('tripay-kanal:'.sha1($this->apiKey.'|'.(int) $this->produksi), now()->addMinutes(self::MENIT_CACHE_KANAL),
            fn () => $this->http()->get($this->url('/merchant/payment-channel'))->throw()->json('data') ?? []);

        return collect($semua)
            ->filter(fn (array $k) => ($k['active'] ?? false)
                && (empty($k['minimum_amount']) || $nominal >= (int) $k['minimum_amount'])
                && (empty($k['maximum_amount']) || $nominal <= (int) $k['maximum_amount']))
            ->map(fn (array $k) => ['kode' => (string) $k['code'], 'nama' => (string) $k['name'], 'grup' => $k['group'] ?? null, 'ikon' => $k['icon_url'] ?? null])
            ->values()->all();
    }

    public function buatTransaksi(TransaksiMidtrans $transaksi, array $tagihan, Pelanggan $pelanggan, ?string $kanal, int $jam): array
    {
        if (! $kanal) {
            throw new RuntimeException('Tripay membutuhkan metode pembayaran.');
        }
        $respons = $this->http()->asForm()->post($this->url('/transaction/create'), [
            'method' => $kanal,
            'merchant_ref' => $transaksi->order_id,
            'amount' => $transaksi->gross_amount,
            'customer_name' => Str::limit($pelanggan->nama, 50, ''),
            // Wajib diisi Tripay; pelanggan tidak menyimpan email, jadi memakai alamat billing
            'customer_email' => config('services.pembayaran.email_pelanggan'),
            'customer_phone' => NomorHp::normalkan((string) $pelanggan->tlp),
            'order_items' => [[
                'sku' => $tagihan['no_tagihan'],
                'name' => Str::limit('Internet '.($tagihan['nama_paket'] ?? '').' '.Carbon::parse($tagihan['periode'])->locale('id')->translatedFormat('M Y'), 50, ''),
                'price' => $transaksi->gross_amount,
                'quantity' => 1,
            ]],
            'callback_url' => route('pembayaran.notifikasi', 'tripay'),
            'return_url' => route('midtrans.selesai', ['order_id' => $transaksi->order_id]),
            'expired_time' => now()->addHours($jam)->getTimestamp(),
            'signature' => hash_hmac('sha256', $this->kodeMerchant.$transaksi->order_id.$transaksi->gross_amount, $this->privateKey),
        ]);
        if ($respons->json('success') !== true) {
            throw new RuntimeException('Tripay menolak transaksi '.$transaksi->order_id.': '.$respons->json('message'));
        }

        return ['redirect_url' => (string) $respons->json('data.checkout_url'), 'referensi' => $respons->json('data.reference'), 'kanal' => $kanal];
    }

    public function status(TransaksiMidtrans $transaksi): ?HasilStatus
    {
        if (! $transaksi->transaction_id) {
            return null;
        }
        $data = $this->http()->get($this->url('/transaction/detail'), ['reference' => $transaksi->transaction_id])->throw()->json('data') ?? [];
        if (($data['merchant_ref'] ?? null) !== $transaksi->order_id) {
            return null;
        }
        $asli = (string) ($data['status'] ?? '');

        return new HasilStatus(
            status: match ($asli) {
                'PAID' => 'lunas',
                'UNPAID' => 'pending',
                'EXPIRED' => 'expire',
                'FAILED' => 'failure',
                'REFUND' => 'ditarik',
                default => 'tidak_dikenal',
            },
            // amount Tripay bisa sudah termasuk biaya yang dibebankan ke pelanggan; yang dicocokkan nominal tagihannya
            nominal: (int) ($data['amount'] ?? 0) - (int) ($data['fee_customer'] ?? 0),
            kanal: $data['payment_method'] ?? null,
            referensi: $data['reference'] ?? null,
            dibayarAt: ! empty($data['paid_at']) ? Carbon::createFromTimestamp((int) $data['paid_at'], config('app.timezone')) : null,
            statusAsli: $asli,
        );
    }

    public function hentikan(TransaksiMidtrans $transaksi): string
    {
        return 'tidak_didukung';
    }

    public function notifikasiSah(Request $request): bool
    {
        $tandaTangan = $request->header('X-Callback-Signature');

        return $this->privateKey !== '' && $request->header('X-Callback-Event') === 'payment_status' && is_string($tandaTangan)
            && hash_equals(hash_hmac('sha256', $request->getContent(), $this->privateKey), $tandaTangan);
    }

    private function url(string $jalur): string
    {
        return ($this->produksi ? 'https://tripay.co.id/api' : 'https://tripay.co.id/api-sandbox').$jalur;
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiKey)->acceptJson()->timeout(config('services.pembayaran.timeout', 15));
    }
}
