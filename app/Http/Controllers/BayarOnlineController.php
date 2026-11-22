<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\TransaksiMidtrans;
use App\Services\Pembayaran\AkunPembayaran;
use App\Services\Pembayaran\HasilStatus;
use App\Support\Audit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Pembayaran online tagihan (Cek Tagihan & dashboard pelanggan) lewat payment gateway Utama cabang pelanggan: Midtrans, Xendit,
 * atau Tripay (lihat AkunPembayaran & menu Payment Gateway).
 *
 * Keamanan: nominal selalu dihitung ulang di server dari tagihan yang belum dibayar; notifikasi diterima hanya bila tanda tangan /
 * token sah untuk akun tempat transaksi dibuat, lalu status & nominal dicek ulang langsung ke API provider; pemrosesan idempoten
 * (satu transaksi hanya bisa mencatat satu pembayaran) dan dikunci per order. Refund/chargeback setelah lunas ditandai untuk
 * ditinjau. Notifikasi yang ditandatangani akun lain tidak bisa melunasi tagihan.
 * (Tabel & model masih bernama transaksi_midtrans karena awalnya hanya Midtrans; isinya transaksi semua provider.)
 */
class BayarOnlineController extends Controller
{
    /** Panjang bagian acak order ID: halaman selesai bisa dibuka dengan order ID, jadi harus mustahil ditebak. */
    private const PANJANG_ACAK_ORDER = 12;

    /** Status yang diproses dari provider; status lain (tidak_dikenal) tidak mengubah transaksi. */
    private const STATUS_DIKENAL = ['lunas', 'pending', 'expire', 'cancel', 'deny', 'failure'];

    /**
     * Tombol "Bayar": buat (atau pakai ulang) transaksi; browser lalu diarahkan ke halaman pembayaran provider.
     * Provider yang mewajibkan metode dipilih di aplikasi (Tripay) menjawab daftar metode lebih dulu (pilih_kanal), lalu
     * dipanggil ulang dengan kanal. ganti=true: pelanggan ingin ganti metode; transaksi lama dihentikan bila provider mendukung.
     */
    public function bayar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_pelanggan' => ['required', 'string', 'max:30'],
            'periode' => ['required', 'date_format:Y-m-d'],
            'ganti' => ['sometimes', 'boolean'],
            'kanal' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);
        $pelanggan = Pelanggan::with(['paket:id,nama_paket,harga', 'lokasi:id,admin_full', 'pembayaran:id,pelanggan_id,periode'])
            ->where('status', 'aktif')->where('id_pelanggan', $data['id_pelanggan'])->first();
        $tagihan = collect($pelanggan?->tagihanBelumDibayar() ?? [])->firstWhere('periode', $data['periode']);
        $akun = $pelanggan ? AkunPembayaran::untukLokasi($pelanggan->lokasi) : null;
        if (! $akun || ! $tagihan || ($tagihan['nominal'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['bayar' => 'Tagihan ini tidak bisa dibayar online. Silakan muat ulang halaman atau hubungi kami.']);
        }

        $kanal = $data['kanal'] ?? null;
        if ($akun->penyedia->butuhKanal()) {
            try {
                $daftar = $akun->penyedia->daftarKanal($tagihan['nominal']);
            } catch (Throwable $e) {
                report($e);
                throw ValidationException::withMessages(['bayar' => 'Pembayaran online sedang tidak tersedia. Silakan coba lagi nanti atau bayar lewat rekening.']);
            }
            if (! $kanal) {
                return response()->json(['pilih_kanal' => $daftar]);
            }
            if (! collect($daftar)->contains('kode', $kanal)) {
                throw ValidationException::withMessages(['bayar' => 'Metode pembayaran tidak tersedia. Silakan pilih metode lain.']);
            }
        } else {
            $kanal = null;
        }

        // Link lama hanya dipakai ulang bila dibuat di akun yang sama (gateway bisa diganti dari menu Payment Gateway)
        $transaksi = TransaksiMidtrans::where(['pelanggan_id' => $pelanggan->id, 'periode' => $data['periode'], 'gross_amount' => $tagihan['nominal']])
            ->where('payment_gateway_id', $akun->gateway?->id)->latest('id')->first();
        // Metode berbeda dari transaksi yang masih berjalan (Tripay) = ganti metode
        $gantiKanal = $kanal !== null && $transaksi?->masihBerlaku() && $transaksi->payment_type !== $kanal;
        if ($transaksi?->masihBerlaku() && ($request->boolean('ganti') || $gantiKanal)) {
            $transaksi = $this->hentikanUntukGanti($transaksi);
        }
        if (! $transaksi?->masihBerlaku()) {
            $transaksi = $this->buatTransaksi($pelanggan, $tagihan, $akun, $kanal);
            if (! $transaksi) {
                throw ValidationException::withMessages(['bayar' => 'Pembayaran online sedang tidak tersedia. Silakan coba lagi nanti atau bayar lewat rekening di bawah.']);
            }
        }

        return response()->json(['redirect_url' => $transaksi->redirect_url]);
    }

    /**
     * Webhook provider (tanpa login & tanpa CSRF). Keabsahan dijamin tanda tangan / token akun tempat transaksi dibuat, lalu status
     * dicek ulang ke API. Order tak dikenal atau milik provider lain dijawab sama seperti tanda tangan salah.
     */
    public function notifikasi(Request $request, string $provider = 'midtrans'): JsonResponse
    {
        $orderId = AkunPembayaran::orderIdNotifikasi($provider, $request);
        $transaksi = $orderId ? TransaksiMidtrans::with('gateway')->firstWhere('order_id', $orderId) : null;
        if (! $transaksi || $transaksi->provider() !== $provider || ! AkunPembayaran::untukTransaksi($transaksi)->notifikasiSah($request)) {
            return response()->json(['success' => false, 'message' => 'Tanda tangan tidak sah'], 403);
        }
        $this->sinkron($transaksi);

        // Tripay mewajibkan jawaban {"success": true}; provider lain cukup HTTP 200
        return response()->json(['success' => true, 'message' => 'OK']);
    }

    /** Halaman tujuan setelah pelanggan selesai di halaman provider; status disinkronkan langsung bila webhook belum masuk. */
    public function selesai(Request $request): Response|RedirectResponse
    {
        $transaksi = TransaksiMidtrans::with(['pelanggan:id,nama,id_pelanggan,user_id', 'pembayaran:id', 'gateway'])->firstWhere('order_id', (string) $request->query('order_id'));
        if ($transaksi?->status === 'pending' && AkunPembayaran::untukTransaksi($transaksi)->aktif()) {
            $transaksi = $this->sinkron($transaksi)->load(['pelanggan:id,nama,id_pelanggan,user_id', 'pembayaran:id']);
        }
        // Pelanggan yang login & pemilik transaksi (mis. bayar dari dashboard) kembali ke dashboard-nya
        $user = $request->user();
        if ($transaksi && $user?->isPelanggan() && $transaksi->pelanggan?->user_id === $user->id) {
            return redirect()->route('akun-saya.pembayaran', ['order_id' => $transaksi->order_id]);
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
     * Hentikan transaksi pending untuk ganti metode. Mengembalikan null bila transaksi lama sudah dihentikan / tidak bisa dihentikan
     * (buat baru), atau transaksi yang sama bila pelanggan belum memilih metode apa pun (halaman provider masih bebas memilih).
     * Bila provider menolak (mis. keburu dibayar), status disinkronkan dan pelanggan diberi tahu.
     */
    private function hentikanUntukGanti(TransaksiMidtrans $transaksi): ?TransaksiMidtrans
    {
        try {
            return Cache::lock('bayar-online:'.$transaksi->order_id, 30)->block(10, function () use ($transaksi) {
                try {
                    $hasil = AkunPembayaran::untukTransaksi($transaksi)->hentikan($transaksi);
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
                $transaksi->update(['status' => 'expire', 'catatan' => $hasil === 'tidak_didukung'
                    ? 'Diganti ke metode lain; kode bayar lama tidak bisa dibatalkan di provider dan berlaku sampai kedaluwarsa'
                    : 'Dihentikan karena pelanggan mengganti metode pembayaran']);

                return null;
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['bayar' => 'Pembayaran sedang diproses. Coba lagi sebentar lagi.']);
        }
    }

    /** @param  array<string, mixed>  $tagihan  satu item Pelanggan::tagihanBelumDibayar() */
    private function buatTransaksi(Pelanggan $pelanggan, array $tagihan, AkunPembayaran $akun, ?string $kanal): ?TransaksiMidtrans
    {
        $jam = max(1, (int) config('services.midtrans.kedaluwarsa_jam', 24));
        $transaksi = TransaksiMidtrans::create([
            // Awalan unik per website + periode + pelanggan + acak: tidak bentrok dengan website lain di akun yang sama
            'order_id' => sprintf('%s-%s-%d-%s', config('services.midtrans.prefix', 'MSSB'), str_replace('-', '', substr($tagihan['periode'], 0, 7)), $pelanggan->id, Str::lower(Str::random(self::PANJANG_ACAK_ORDER))),
            'pelanggan_id' => $pelanggan->id,
            'payment_gateway_id' => $akun->gateway?->id,
            'periode' => $tagihan['periode'],
            'gross_amount' => $tagihan['nominal'],
            'payment_type' => $kanal,
            'kedaluwarsa_at' => now()->addHours($jam),
        ]);
        try {
            $hasil = $akun->penyedia->buatTransaksi($transaksi, $tagihan, $pelanggan, $kanal, $jam);
            $transaksi->update(['redirect_url' => $hasil['redirect_url'] ?: null, 'transaction_id' => $hasil['referensi'], 'payment_type' => $hasil['kanal'] ?? $kanal]);
        } catch (Throwable $e) {
            report($e);
            $transaksi->update(['status' => 'failure', 'catatan' => 'Gagal membuat transaksi di '.$akun->penyedia->nama()]);

            return null;
        }

        return $transaksi->redirect_url ? $transaksi : null;
    }

    /**
     * Ambil status dari API provider lalu proses; aman dipanggil berkali-kali (webhook ganda, halaman selesai).
     * Satu proses per order lewat kunci cache: lockForUpdate tidak berlaku di SQLite, dan status diambil di dalam kunci
     * agar hasil cek yang lebih lama tidak menimpa yang lebih baru.
     */
    private function sinkron(TransaksiMidtrans $transaksi): TransaksiMidtrans
    {
        try {
            return Cache::lock('bayar-online:'.$transaksi->order_id, 30)->block(10, fn () => $this->prosesStatus($transaksi->fresh('gateway')));
        } catch (LockTimeoutException) {
            return $transaksi;
        }
    }

    private function prosesStatus(TransaksiMidtrans $transaksi): TransaksiMidtrans
    {
        if ($transaksi->status === 'ditarik') {
            return $transaksi;
        }
        $penyedia = AkunPembayaran::untukTransaksi($transaksi);
        try {
            $hasil = $penyedia->status($transaksi);
        } catch (Throwable $e) {
            report($e);

            return $transaksi;
        }
        if (! $hasil) {
            return $transaksi;
        }
        $nama = $penyedia->nama();

        return Audit::sebagai($nama, fn () => DB::transaction(function () use ($transaksi, $hasil, $nama) {
            $transaksi = TransaksiMidtrans::lockForUpdate()->findOrFail($transaksi->id);
            $info = ['payment_type' => $hasil->kanal ?? $transaksi->payment_type, 'transaction_id' => $hasil->referensi ?? $transaksi->transaction_id];
            if (in_array($transaksi->status, TransaksiMidtrans::LUNAS, true)) {
                // Uang ditarik kembali setelah lunas: jangan dihapus otomatis, tandai agar admin meninjau & membatalkan pembayarannya
                if ($hasil->status === 'ditarik') {
                    $transaksi->update(['status' => 'ditarik', 'catatan' => "{$nama}: {$hasil->statusAsli} setelah lunas, tinjau & batalkan pembayaran bila perlu", ...$info]);
                    Audit::catat('Pembayaran', 'update', "Pembayaran {$nama} {$transaksi->order_id} berstatus {$hasil->statusAsli} (uang ditarik kembali), tinjau pembayaran pelanggan ini", $transaksi);
                }

                return $transaksi;
            }
            if (! in_array($hasil->status, self::STATUS_DIKENAL, true)) {
                return $transaksi;
            }
            if ($hasil->status !== 'lunas') {
                $transaksi->update(['status' => $hasil->status, ...$info]);

                return $transaksi;
            }
            if ($hasil->nominal !== $transaksi->gross_amount) {
                $transaksi->update(['status' => 'selisih', 'catatan' => "Nominal dari {$nama} berbeda dengan tagihan, periksa manual", ...$info]);
                Audit::catat('Pembayaran', 'update', "Pembayaran {$nama} {$transaksi->order_id} nominalnya berbeda dari tagihan, tidak dicatat otomatis", $transaksi);

                return $transaksi;
            }

            return $this->catatLunas($transaksi, $hasil, $info);
        }));
    }

    /** @param  array<string, mixed>  $info */
    private function catatLunas(TransaksiMidtrans $transaksi, HasilStatus $hasil, array $info): TransaksiMidtrans
    {
        $dibayar = $hasil->dibayarAt ?? now();
        $pelanggan = Pelanggan::with('paket:id,nama_paket,harga')->find($transaksi->pelanggan_id);
        if (! $pelanggan?->paket) {
            $transaksi->update(['status' => 'lunas_ganda', 'dibayar_at' => $dibayar, 'catatan' => 'Pelanggan/paket sudah tidak ada, catat manual', ...$info]);

            return $transaksi;
        }
        try {
            $pembayaran = Pembayaran::catatUntuk($pelanggan, [
                'periode' => $transaksi->periode, 'harga' => $transaksi->gross_amount, 'metode' => $transaksi->provider(), 'rekening_id' => null,
                'diskon_tipe' => null, 'diskon_nilai' => 0, 'diskon' => 0, 'jml_bayar' => $transaksi->gross_amount,
            ], null, $dibayar);
            $transaksi->update(['status' => 'lunas', 'dibayar_at' => $dibayar, 'pembayaran_id' => $pembayaran->id, ...$info]);
        } catch (UniqueConstraintViolationException) {
            // Periode keburu dibayar dengan cara lain (cash/transfer/kode bayar lain): uang perlu dikembalikan / dialihkan manual
            $transaksi->update(['status' => 'lunas_ganda', 'dibayar_at' => $dibayar, 'catatan' => 'Periode sudah dibayar dengan cara lain, perlu refund/alihkan manual', ...$info]);
            Audit::catat('Pembayaran', 'update', "Pembayaran online {$transaksi->order_id} masuk untuk periode yang sudah dibayar pelanggan {$pelanggan->nama}, perlu refund/alihkan manual", $transaksi);
        }

        return $transaksi;
    }
}
