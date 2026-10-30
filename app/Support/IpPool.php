<?php

namespace App\Support;

/** Hitung IP kosong dari ranges IP Pool MikroTik, mis. "10.12.10.2-10.12.10.254,10.12.11.0/24". */
class IpPool
{
    /** Batas IP yang dihitung per pool agar pool raksasa tidak membebani server. */
    private const MAKS_IP = 65536;

    /**
     * @param  array<int, string>  $terpakai  IP yang sudah dipakai
     * @return array{kosong: list<string>, jumlah_kosong: int} kosong = beberapa IP kosong pertama
     */
    public static function kosong(string $ranges, array $terpakai, int $batas = 20): array
    {
        $terpakai = array_flip(array_filter(array_map(fn ($ip) => ip2long((string) $ip), $terpakai), fn ($n) => $n !== false));
        $kosong = [];
        $jumlah = 0;
        $dihitung = 0;
        foreach (self::rentang($ranges) as [$awal, $akhir]) {
            for ($n = $awal; $n <= $akhir && $dihitung < self::MAKS_IP; $n++, $dihitung++) {
                if (isset($terpakai[$n])) {
                    continue;
                }
                $jumlah++;
                if (count($kosong) < $batas) {
                    $kosong[] = long2ip($n);
                }
            }
        }

        return ['kosong' => $kosong, 'jumlah_kosong' => $jumlah];
    }

    /** @return list<array{0: int, 1: int}> pasangan awal-akhir; CIDR tanpa alamat jaringan & broadcast */
    private static function rentang(string $ranges): array
    {
        $hasil = [];
        foreach (array_filter(array_map('trim', explode(',', $ranges))) as $bagian) {
            if (str_contains($bagian, '/')) {
                [$ip, $prefix] = explode('/', $bagian, 2);
                $dasar = ip2long($ip);
                $prefix = (int) $prefix;
                if ($dasar === false || $prefix < 8 || $prefix > 32) {
                    continue;
                }
                $ukuran = 2 ** (32 - $prefix);
                $jaringan = $dasar & ~($ukuran - 1);
                $hasil[] = $ukuran > 2 ? [$jaringan + 1, $jaringan + $ukuran - 2] : [$jaringan, $jaringan + $ukuran - 1];

                continue;
            }
            [$awal, $akhir] = array_map(fn ($ip) => ip2long(trim($ip)), array_pad(explode('-', $bagian, 2), 2, $bagian));
            if ($awal !== false && $akhir !== false && $awal <= $akhir) {
                $hasil[] = [$awal, $akhir];
            }
        }

        return $hasil;
    }
}
