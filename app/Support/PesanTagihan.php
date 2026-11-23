<?php

namespace App\Support;

use App\Models\Pelanggan;
use App\Models\TemplatePesan;
use Illuminate\Support\Carbon;

/**
 * Isi pesan WhatsApp tagihan satu pelanggan untuk satu bulan tagihan, memakai template cabang pelanggan (menu Notifikasi)
 * atau kalimat bawaan. Nominal, periode, & jatuh tempo sama dengan invoice (Pelanggan::invoice).
 */
class PesanTagihan
{
    /** Ganti placeholder template dengan data tagihan. */
    public static function untuk(Pelanggan $pelanggan, string $bulanTagihan, ?string $template = null): string
    {
        $pelanggan->loadMissing(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi']);
        $invoice = $pelanggan->invoice($bulanTagihan);

        return self::isi($template ?? TemplatePesan::isiUntuk($pelanggan->id_lokasi), [
            '{nama}' => $pelanggan->nama,
            '{id_pelanggan}' => $pelanggan->id_pelanggan ?? '-',
            '{paket}' => $invoice['nama_paket'] ?? '-',
            '{periode}' => self::bulan($invoice['periode_pembayaran']),
            '{tagihan}' => 'Rp '.number_format((int) $invoice['nominal'], 0, ',', '.'),
            '{jatuh_tempo}' => $invoice['jatuh_tempo'] ? Carbon::parse($invoice['jatuh_tempo'])->locale('id')->translatedFormat('j F Y') : '-',
            '{link}' => $pelanggan->linkCekTagihan(),
            '{cabang}' => $pelanggan->lokasi?->nama_lokasi ?? '',
        ]);
    }

    /**
     * Contoh pesan untuk pratinjau di menu Notifikasi (data rekaan).
     */
    public static function contoh(string $template, string $cabang): string
    {
        return self::isi($template, [
            '{nama}' => 'Budi Santoso', '{id_pelanggan}' => 'MSS-000123', '{paket}' => 'Home 20 Mbps',
            '{periode}' => self::bulan(today()->startOfMonth()->toDateString()), '{tagihan}' => 'Rp 200.000',
            '{jatuh_tempo}' => today()->day(10)->locale('id')->translatedFormat('j F Y'),
            '{link}' => url('/cek-tagihan/kartu/123?signature=…'), '{cabang}' => $cabang,
        ]);
    }

    private static function isi(string $template, array $nilai): string
    {
        return trim(strtr(str_replace("\r\n", "\n", $template), $nilai));
    }

    private static function bulan(string $tanggal): string
    {
        return Carbon::parse($tanggal)->locale('id')->translatedFormat('F Y');
    }
}
