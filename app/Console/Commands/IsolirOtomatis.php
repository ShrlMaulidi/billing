<?php

namespace App\Console\Commands;

use App\Services\Mikrotik\AutoIsolir;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('isolir:otomatis')]
#[Description('Isolir pelanggan yang tagihannya lewat jatuh tempo, sesuai Kelola Data › Auto Isolir (dijalankan penjadwal tiap menit)')]
class IsolirOtomatis extends Command
{
    public function handle(AutoIsolir $autoIsolir): int
    {
        Cache::forever(AutoIsolir::KUNCI_DETAK, now()->toIso8601String());
        if (! $autoIsolir->waktunyaJalan()) {
            return self::SUCCESS;
        }

        $hasil = $autoIsolir->jalankan();
        if ($hasil === null) {
            $this->warn('Auto isolir sedang berjalan di proses lain.');

            return self::SUCCESS;
        }
        $this->info("{$hasil['diisolir']} pelanggan diisolir, {$hasil['gagal']} gagal, {$hasil['ditunda']} ditunda ke besok.");

        return self::SUCCESS;
    }
}
