<?php

namespace App\Services\Pembayaran;

use App\Models\Pelanggan;
use App\Models\TransaksiMidtrans;
use App\Services\Midtrans\MidtransClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Midtrans Snap: pelanggan memilih metode di halaman Midtrans. Notifikasi diarahkan per transaksi lewat X-Override-Notification
 * dan diverifikasi dengan signature_key SHA-512 (Server Key / Signature Secret Key).
 */
final class MidtransPenyedia implements Penyedia
{
    /** Status Midtrans → status seragam. */
    private const STATUS = ['settlement' => 'lunas', 'capture' => 'lunas', 'pending' => 'pending', 'expire' => 'expire', 'cancel' => 'cancel', 'deny' => 'deny', 'failure' => 'failure'];

    /** Status Midtrans yang menarik kembali uang yang sudah lunas (refund oleh merchant / chargeback kartu kredit). */
    private const DITARIK = ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'];

    public function __construct(private readonly MidtransClient $client) {}

    public function nama(): string
    {
        return 'Midtrans';
    }

    public function aktif(): bool
    {
        return $this->client->aktif();
    }

    public function sesuaiMode(): bool
    {
        return $this->client->sesuaiMode();
    }

    public function ujiKoneksi(): bool
    {
        return $this->client->ujiKoneksi();
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
        $snap = $this->client->buatTransaksi([
            'transaction_details' => ['order_id' => $transaksi->order_id, 'gross_amount' => $transaksi->gross_amount],
            'item_details' => [[
                'id' => $tagihan['no_tagihan'],
                'price' => $transaksi->gross_amount,
                'quantity' => 1,
                'name' => Str::limit('Internet '.($tagihan['nama_paket'] ?? '').' '.Carbon::parse($tagihan['periode'])->locale('id')->translatedFormat('M Y'), 50, ''),
            ]],
            'customer_details' => ['first_name' => Str::limit($pelanggan->nama, 50, '')],
            'callbacks' => ['finish' => route('midtrans.selesai')],
            'expiry' => ['unit' => 'hour', 'duration' => $jam],
        ], route('midtrans.notifikasi'));

        return ['redirect_url' => (string) ($snap['redirect_url'] ?? ''), 'referensi' => null, 'kanal' => null];
    }

    public function status(TransaksiMidtrans $transaksi): ?HasilStatus
    {
        $status = $this->client->status($transaksi->order_id);
        $asli = (string) ($status['transaction_status'] ?? '');
        if (($status['order_id'] ?? null) !== $transaksi->order_id || $asli === '') {
            return null;
        }
        $seragam = in_array($asli, self::DITARIK, true) ? 'ditarik' : (self::STATUS[$asli] ?? 'tidak_dikenal');
        // Kartu kredit: capture baru dianggap lunas bila Midtrans menyatakan lolos pemeriksaan fraud
        if ($asli === 'capture' && ($status['fraud_status'] ?? null) !== 'accept') {
            $seragam = 'pending';
        }

        return new HasilStatus(
            status: $seragam,
            nominal: (int) round((float) ($status['gross_amount'] ?? 0)),
            kanal: $status['payment_type'] ?? null,
            referensi: $status['transaction_id'] ?? null,
            dibayarAt: isset($status['settlement_time']) ? Carbon::parse($status['settlement_time']) : null,
            statusAsli: $asli,
        );
    }

    public function hentikan(TransaksiMidtrans $transaksi): string
    {
        return $this->client->kedaluwarsakan($transaksi->order_id);
    }

    public function notifikasiSah(Request $request): bool
    {
        return $this->client->tandaTanganSah($request->all());
    }
}
