<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\TransaksiMidtrans;
use App\Services\Midtrans\AkunMidtrans;
use App\Support\Audit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Pembayaran online Midtrans (Snap) dari halaman Cek Tagihan. Akun Midtrans dipilih AkunMidtrans: cabang non Admin Full
 * memakai gateway perusahaan, cabang Admin Full memakai gateway miliknya sendiri (menu Payment Gateway).
 *
 * Keamanan: nominal selalu dihitung ulang di server dari tagihan yang belum dibayar; notifikasi diterima hanya bila
 * signature_key sah, lalu status & nominal dicek ulang langsung ke API Midtrans; pemrosesan idempoten (satu transaksi
 * hanya bisa mencatat satu pembayaran) dan dikunci per order. Refund/chargeback setelah lunas ditandai untuk ditinjau.
 * Setiap transaksi menyimpan akun tempat ia dibuat; tanda tangan, cek status, dan ganti metode selalu memakai akun itu,
 * sehingga notifikasi yang ditandatangani akun lain tidak bisa melunasi tagihan.
 */
class MidtransController extends Controller
{
    /** Status Midtrans → status transaksi billing. */
    private const STATUS = ['settlement' => 'lunas', 'capture' => 'lunas', 'pending' => 'pending', 'expire' => 'expire', 'cancel' => 'cancel', 'deny' => 'deny', 'failure' => 'failure'];

    /** Status Midtrans yang menarik kembali uang yang sudah lunas (refund oleh merchant / chargeback kartu kredit). */
    private const DITARIK = ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'];

    /** Panjang bagian acak order ID: halaman selesai bisa dibuka dengan order ID, jadi harus mustahil ditebak. */
    private const PANJANG_ACAK_ORDER = 12;

    /**
     * Tombol "Bayar Online": buat (atau pakai ulang) transaksi Snap; browser lalu diarahkan ke redirect_url Midtrans.
     * ganti=true: pelanggan ingin ganti metode (mis. QRIS → VA). Transaksi lama dihentikan di Midtrans lebih dulu agar
     * tidak bisa terbayar dua kali, baru dibuat transaksi baru.
     */
    public function bayar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_pelanggan' => ['required', 'string', 'max:30'],
            'periode' => ['required', 'date_format:Y-m-d'],
            'ganti' => ['sometimes', 'boolean'],
        ]);
        $pelanggan = Pelanggan::with(['paket:id,nama_paket,harga', 'lokasi:id,admin_full', 'pembayaran:id,pelanggan_id,periode'])
            ->where('status', 'aktif')->where('id_pelanggan', $data['id_pelanggan'])->first();
        $tagihan = collect($pelanggan?->tagihanBelumDibayar() ?? [])->firstWhere('periode', $data['periode']);
        $akun = $pelanggan ? AkunMidtrans::untukLokasi($pelanggan->lokasi) : null;
        if (! $akun || ! $tagihan || ($tagihan['nominal'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['bayar' => 'Tagihan ini tidak bisa dibayar online. Silakan muat ulang halaman atau hubungi kami.']);
        }

        // Link lama hanya dipakai ulang bila dibuat di akun Midtrans yang sama (gateway bisa diganti dari menu Payment Gateway)
        $transaksi = TransaksiMidtrans::where(['pelanggan_id' => $pelanggan->id, 'periode' => $data['periode'], 'gross_amount' => $tagihan['nominal']])
            ->where('payment_gateway_id', $akun->gateway?->id)->latest('id')->first();
        if ($transaksi?->masihBerlaku() && $request->boolean('ganti')) {
            $transaksi = $this->hentikanUntukGanti($transaksi);
        }
        if (! $transaksi?->masihBerlaku()) {
            $transaksi = $this->buatTransaksi($pelanggan, $tagihan, $akun);
            if (! $transaksi) {
                throw ValidationException::withMessages(['bayar' => 'Pembayaran online sedang tidak tersedia. Silakan coba lagi nanti atau bayar lewat rekening di bawah.']);
            }
        }

        return response()->json(['redirect_url' => $transaksi->redirect_url]);
    }

    /**
     * Webhook Midtrans (X-Override-Notification). Tanpa login & tanpa CSRF; keabsahan dijamin tanda tangan (dengan kunci akun
     * tempat transaksi dibuat) + cek status ke API. Order tak dikenal dijawab sama seperti tanda tangan salah.
     */
    public function notifikasi(Request $request): JsonResponse
    {
        $orderId = $request->input('order_id');
        $transaksi = is_string($orderId) ? TransaksiMidtrans::with('gateway')->firstWhere('order_id', $orderId) : null;
        if (! $transaksi || ! AkunMidtrans::untukTransaksi($transaksi)->tandaTanganSah($request->all())) {
            return response()->json(['message' => 'Tanda tangan tidak sah'], 403);
        }
        $this->sinkron($transaksi);

        return response()->json(['message' => 'OK']);
    }

    /** Halaman tujuan setelah pelanggan selesai di Midtrans; status disinkronkan langsung bila webhook belum masuk. */
    public function selesai(Request $request): Response
    {
        $transaksi = TransaksiMidtrans::with(['pelanggan:id,nama,id_pelanggan', 'pembayaran:id', 'gateway'])->firstWhere('order_id', (string) $request->query('order_id'));
        if ($transaksi?->status === 'pending' && AkunMidtrans::untukTransaksi($transaksi)->aktif()) {
            $transaksi = $this->sinkron($transaksi)->load(['pelanggan:id,nama,id_pelanggan', 'pembayaran:id']);
        }

        return Inertia::render('CekTagihan/Selesai', [
            'transaksi' => $transaksi ? [
                'status' => in_array($transaksi->status, TransaksiMidtrans::LUNAS, true) ? 'lunas' : $transaksi->status,
                'nominal' => $transaksi->gross_amount,
                'periode' => $transaksi->periode,
                'id_pelanggan' => $transaksi->pelanggan?->id_pelanggan,
                // Nama disamarkan seperti di Cek Tagihan
                'nama' => $transaksi->pelanggan ? CekTagihanController::samarkan($transaksi->pelanggan->nama) : null,
                'link_kwitansi' => $transaksi->status === 'lunas' && $transaksi->pembayaran ? CekTagihanController::linkKwitansi($transaksi->pembayaran) : null,
            ] : null,
        ]);
    }

    /**
     * Hentikan transaksi pending untuk ganti metode. Mengembalikan null bila transaksi lama sudah dihentikan (buat baru),
     * atau transaksi yang sama bila pelanggan belum memilih metode apa pun (halaman Midtrans-nya masih bebas memilih).
     * Bila Midtrans menolak (mis. keburu dibayar), status disinkronkan dan pelanggan diberi tahu.
     */
    private function hentikanUntukGanti(TransaksiMidtrans $transaksi): ?TransaksiMidtrans
    {
        try {
            return Cache::lock('midtrans:'.$transaksi->order_id, 30)->block(10, function () use ($transaksi) {
                try {
                    $hasil = AkunMidtrans::untukTransaksi($transaksi)->kedaluwarsakan($transaksi->order_id);
                } catch (Throwable $e) {
                    report($e);
                    $lunas = in_array($this->prosesStatus($transaksi->fresh('gateway'))->status, TransaksiMidtrans::LUNAS, true);
                    throw ValidationException::withMessages(['bayar' => $lunas
                        ? 'Tagihan ini sudah dibayar. Silakan muat ulang halaman.'
                        : 'Metode pembayaran belum bisa diganti saat ini. Selesaikan pembayaran dengan metode sebelumnya, atau hubungi kami.']);
                }
                if ($hasil === 'belum_ada') {
                    return $transaksi;
                }
                $transaksi->update(['status' => 'expire', 'catatan' => 'Dihentikan karena pelanggan mengganti metode pembayaran']);

                return null;
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['bayar' => 'Pembayaran sedang diproses. Coba lagi sebentar lagi.']);
        }
    }

    /** @param  array<string, mixed>  $tagihan  satu item Pelanggan::tagihanBelumDibayar() */
    private function buatTransaksi(Pelanggan $pelanggan, array $tagihan, AkunMidtrans $akun): ?TransaksiMidtrans
    {
        $jam = max(1, (int) config('services.midtrans.kedaluwarsa_jam', 24));
        $transaksi = TransaksiMidtrans::create([
            // Awalan unik per website + periode + pelanggan + acak: tidak bentrok dengan website lain di akun Midtrans yang sama
            'order_id' => sprintf('%s-%s-%d-%s', config('services.midtrans.prefix', 'MSSB'), str_replace('-', '', substr($tagihan['periode'], 0, 7)), $pelanggan->id, Str::lower(Str::random(self::PANJANG_ACAK_ORDER))),
            'pelanggan_id' => $pelanggan->id,
            'payment_gateway_id' => $akun->gateway?->id,
            'periode' => $tagihan['periode'],
            'gross_amount' => $tagihan['nominal'],
            'kedaluwarsa_at' => now()->addHours($jam),
        ]);
        try {
            $snap = $akun->client->buatTransaksi([
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
            $transaksi->update(['redirect_url' => $snap['redirect_url'] ?? null]);
        } catch (Throwable $e) {
            report($e);
            $transaksi->update(['status' => 'failure', 'catatan' => 'Gagal membuat transaksi di Midtrans']);

            return null;
        }

        return $transaksi->redirect_url ? $transaksi : null;
    }

    /**
     * Ambil status dari API Midtrans lalu proses; aman dipanggil berkali-kali (webhook ganda, halaman selesai).
     * Satu proses per order lewat kunci cache: lockForUpdate tidak berlaku di SQLite, dan status diambil di dalam kunci
     * agar hasil cek yang lebih lama tidak menimpa yang lebih baru.
     */
    private function sinkron(TransaksiMidtrans $transaksi): TransaksiMidtrans
    {
        try {
            return Cache::lock('midtrans:'.$transaksi->order_id, 30)->block(10, fn () => $this->prosesStatus($transaksi->fresh('gateway')));
        } catch (LockTimeoutException) {
            return $transaksi;
        }
    }

    private function prosesStatus(TransaksiMidtrans $transaksi): TransaksiMidtrans
    {
        if ($transaksi->status === 'ditarik') {
            return $transaksi;
        }
        try {
            $status = AkunMidtrans::untukTransaksi($transaksi)->status($transaksi->order_id);
        } catch (Throwable $e) {
            report($e);

            return $transaksi;
        }
        $statusMidtrans = (string) ($status['transaction_status'] ?? '');
        if (($status['order_id'] ?? null) !== $transaksi->order_id || $statusMidtrans === '') {
            return $transaksi;
        }

        return Audit::sebagai('Midtrans', fn () => DB::transaction(function () use ($transaksi, $status, $statusMidtrans) {
            $transaksi = TransaksiMidtrans::lockForUpdate()->findOrFail($transaksi->id);
            $info = ['payment_type' => $status['payment_type'] ?? $transaksi->payment_type, 'transaction_id' => $status['transaction_id'] ?? $transaksi->transaction_id];
            if (in_array($transaksi->status, TransaksiMidtrans::LUNAS, true)) {
                // Uang ditarik kembali setelah lunas: jangan dihapus otomatis, tandai agar admin meninjau & membatalkan pembayarannya
                if (in_array($statusMidtrans, self::DITARIK, true)) {
                    $transaksi->update(['status' => 'ditarik', 'catatan' => "Midtrans: {$statusMidtrans} setelah lunas, tinjau & batalkan pembayaran bila perlu", ...$info]);
                    Audit::catat('Pembayaran', 'update', "Pembayaran Midtrans {$transaksi->order_id} berstatus {$statusMidtrans} (uang ditarik kembali), tinjau pembayaran pelanggan ini", $transaksi);
                }

                return $transaksi;
            }
            $baru = self::STATUS[$statusMidtrans] ?? $transaksi->status;
            // Kartu kredit: capture baru dianggap lunas bila Midtrans menyatakan lolos pemeriksaan fraud
            if ($statusMidtrans === 'capture' && ($status['fraud_status'] ?? null) !== 'accept') {
                $baru = 'pending';
            }
            if ($baru !== 'lunas') {
                $transaksi->update(['status' => $baru, ...$info]);

                return $transaksi;
            }
            if ((int) round((float) ($status['gross_amount'] ?? 0)) !== $transaksi->gross_amount) {
                $transaksi->update(['status' => 'selisih', 'catatan' => 'Nominal dari Midtrans berbeda dengan tagihan, periksa manual', ...$info]);
                Audit::catat('Pembayaran', 'update', "Pembayaran Midtrans {$transaksi->order_id} nominalnya berbeda dari tagihan, tidak dicatat otomatis", $transaksi);

                return $transaksi;
            }

            return $this->catatLunas($transaksi, $status, $info);
        }));
    }

    /** @param  array<string, mixed>  $status  @param  array<string, mixed>  $info */
    private function catatLunas(TransaksiMidtrans $transaksi, array $status, array $info): TransaksiMidtrans
    {
        $dibayar = isset($status['settlement_time']) ? Carbon::parse($status['settlement_time']) : now();
        $pelanggan = Pelanggan::with('paket:id,nama_paket,harga')->find($transaksi->pelanggan_id);
        if (! $pelanggan?->paket) {
            $transaksi->update(['status' => 'lunas_ganda', 'dibayar_at' => $dibayar, 'catatan' => 'Pelanggan/paket sudah tidak ada, catat manual', ...$info]);

            return $transaksi;
        }
        try {
            $pembayaran = Pembayaran::catatUntuk($pelanggan, [
                'periode' => $transaksi->periode, 'harga' => $transaksi->gross_amount, 'metode' => 'midtrans', 'rekening_id' => null,
                'diskon_tipe' => null, 'diskon_nilai' => 0, 'diskon' => 0, 'jml_bayar' => $transaksi->gross_amount,
            ], null, $dibayar);
            $transaksi->update(['status' => 'lunas', 'dibayar_at' => $dibayar, 'pembayaran_id' => $pembayaran->id, ...$info]);
        } catch (UniqueConstraintViolationException) {
            // Periode keburu dibayar dengan cara lain (cash/transfer): uang Midtrans perlu dikembalikan / dialihkan manual
            $transaksi->update(['status' => 'lunas_ganda', 'dibayar_at' => $dibayar, 'catatan' => 'Periode sudah dibayar dengan cara lain, perlu refund/alihkan manual', ...$info]);
            Audit::catat('Pembayaran', 'update', "Pembayaran Midtrans {$transaksi->order_id} masuk untuk periode yang sudah dibayar pelanggan {$pelanggan->nama}, perlu refund/alihkan manual", $transaksi);
        }

        return $transaksi;
    }
}
