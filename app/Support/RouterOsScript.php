<?php

namespace App\Support;

/**
 * Penulisan nilai ke script RouterOS yang di-paste admin ke New Terminal router.
 * Nilai dari database/form wajib lewat quote(): tanpa escape, baris baru di dalam nilai
 * akan dieksekusi router sebagai perintah tambahan (script injection).
 */
class RouterOsScript
{
    /**
     * String RouterOS ber-tanda kutip. ", \ dan $ di-escape; ? di-escape agar tidak memicu bantuan terminal;
     * karakter kontrol (baris baru, tab, dll.) ditulis sebagai kode hex \XX sehingga tetap satu baris.
     */
    public static function quote(string $nilai): string
    {
        $aman = preg_replace_callback(
            '/[\x00-\x1F\x7F"\\\\$?]/',
            fn (array $m) => ctype_cntrl($m[0]) ? sprintf('\\%02X', ord($m[0])) : '\\'.$m[0],
            $nilai,
        );

        return '"'.$aman.'"';
    }
}
