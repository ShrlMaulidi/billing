<?php

namespace App\Services\Pembayaran;

use App\Models\Pelanggan;
use App\Models\TransaksiMidtrans;
use Illuminate\Http\Request;

/**
 * Kontrak satu provider payment gateway (Midtrans, Xendit, Tripay) untuk satu akun. Alur bisnisnya sama untuk semua provider
 * (lihat BayarOnlineController): nominal dihitung di server, status selalu dicek ulang ke API provider, diproses idempoten.
 */
interface Penyedia
{
    /** Nama provider untuk tampilan & log, mis. "Midtrans". */
    public function nama(): string;

    /** Kunci terisi dan cocok dengan mode (Sandbox/Production); bila tidak, tombol bayar online disembunyikan. */
    public function aktif(): bool;

    /** Kunci cocok dengan mode yang dipilih (bila provider membedakan kunci sandbox & produksi lewat awalannya). */
    public function sesuaiMode(): bool;

    /** Kunci diterima API provider (true) atau ditolak (false); gangguan jaringan melempar exception. */
    public function ujiKoneksi(): bool;

    /** Pelanggan harus memilih metode bayar di aplikasi sebelum transaksi dibuat (mis. Tripay closed payment). */
    public function butuhKanal(): bool;

    /**
     * Metode bayar aktif yang bisa dipakai untuk nominal ini (hanya bila butuhKanal()).
     *
     * @return list<array{kode: string, nama: string, grup: ?string, ikon: ?string}>
     */
    public function daftarKanal(int $nominal): array;

    /**
     * Buat transaksi di provider untuk transaksi billing yang sudah tersimpan (order_id & nominal dari server).
     *
     * @param  array<string, mixed>  $tagihan  satu item Pelanggan::tagihanBelumDibayar()
     * @return array{redirect_url: string, referensi: ?string, kanal: ?string}
     */
    public function buatTransaksi(TransaksiMidtrans $transaksi, array $tagihan, Pelanggan $pelanggan, ?string $kanal, int $jam): array;

    /** Status terbaru langsung dari API provider (sumber kebenaran); null bila jawaban bukan untuk transaksi ini. */
    public function status(TransaksiMidtrans $transaksi): ?HasilStatus;

    /**
     * Hentikan transaksi pending agar tidak bisa dibayar lagi (pelanggan ganti metode).
     * 'belum_ada' = pelanggan belum memilih metode (link lama masih bisa dipakai); 'tidak_didukung' = provider tidak punya
     * fitur pembatalan (kode lama tetap berlaku sampai kedaluwarsa). Gagal/ditolak melempar exception.
     *
     * @return 'kedaluwarsa'|'belum_ada'|'tidak_didukung'
     */
    public function hentikan(TransaksiMidtrans $transaksi): string;

    /** Notifikasi (webhook) benar dari provider untuk akun ini: tanda tangan / token cocok. */
    public function notifikasiSah(Request $request): bool;
}
