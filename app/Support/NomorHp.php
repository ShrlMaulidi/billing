<?php

namespace App\Support;

/**
 * Nomor HP pelanggan dalam satu format (08…), dipakai pencarian Cek Tagihan dan sebagai username akun pelanggan.
 * 0812-3456-7890, 62812…, dan +62 812… dianggap nomor yang sama.
 */
class NomorHp
{
    /** Buang selain angka, awalan 62 diganti 0. */
    public static function normalkan(string $tlp): string
    {
        $angka = preg_replace('/\D/', '', $tlp);

        return str_starts_with($angka, '62') ? '0'.substr($angka, 2) : $angka;
    }

    /** Nomor yang layak jadi username akun pelanggan: diawali 0, total 10–15 digit setelah dinormalkan. */
    public static function sah(string $tlp): bool
    {
        return preg_match('/^0\d{9,14}$/', self::normalkan($tlp)) === 1;
    }

    /** Teks login yang berupa nomor HP (hanya angka, spasi, +, -, titik) dinormalkan; selain itu dikembalikan apa adanya. */
    public static function normalkanBilaNomor(string $teks): string
    {
        return preg_match('/^\+?[0-9][0-9 .\-]{8,}$/', trim($teks)) === 1 ? self::normalkan($teks) : $teks;
    }
}
