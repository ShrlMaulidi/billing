<?php

use App\Models\AuditLog;
use App\Models\Lokasi;
use App\Models\Pelanggan;
use App\Models\User;
use App\Services\AkunPelanggan;
use App\Support\NomorHp;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Spatie\Permission\Models\Role;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Buat user login baru, atau reset kata sandi bila username sudah ada.
// Kata sandi diketik tersembunyi (tidak tersimpan di riwayat terminal).
Artisan::command('user:create {username} {--name= : Nama tampilan} {--role=Super Admin : Role user} {--cabang= : Kode cabang (kosong = semua cabang)}', function (string $username) {
    if (! Role::where('name', $this->option('role'))->exists()) {
        $this->error("Role {$this->option('role')} belum ada. Jalankan: php artisan db:seed --class=HakAksesSeeder");

        return 1;
    }
    $lokasi = $this->option('cabang') ? Lokasi::firstWhere('kode_lokasi', $this->option('cabang')) : null;
    if ($this->option('cabang') && ! $lokasi) {
        $this->error("Cabang dengan kode {$this->option('cabang')} tidak ditemukan.");

        return 1;
    }

    $user = User::firstWhere('username', $username);
    if ($user && ! $this->confirm("User {$username} sudah ada. Reset kata sandinya?")) {
        return 1;
    }

    $password = $this->secret('Kata sandi (min. 8 karakter)');
    if (strlen((string) $password) < 8) {
        $this->error('Kata sandi minimal 8 karakter.');

        return 1;
    }
    if ($password !== $this->secret('Ulangi kata sandi')) {
        $this->error('Kata sandi tidak sama.');

        return 1;
    }

    if ($user) {
        $user->update(['password' => $password]);
        $this->info("Kata sandi {$username} berhasil direset.");
    } else {
        User::create(['username' => $username, 'name' => $this->option('name') ?: $username, 'id_lokasi' => $lokasi?->id, 'password' => $password])
            ->syncRoles($this->option('role'));
        $this->info("User {$username} berhasil dibuat. Silakan login.");
    }

    return 0;
})->purpose('Buat user login atau reset kata sandinya');

// Buat akun login untuk pelanggan lama yang belum punya akun (username & password awal = nomor HP).
// Pelanggan bernomor HP tidak valid, atau nomornya dipakai username user staf, dilewati dan ditampilkan.
Artisan::command('pelanggan:buat-akun', function (AkunPelanggan $akunPelanggan) {
    $dilewati = [];
    $dibuat = 0;
    Pelanggan::whereNull('user_id')->where('status', '!=', 'berhenti')->lazyById()
        ->each(function (Pelanggan $p) use ($akunPelanggan, &$dilewati, &$dibuat) {
            $alasan = match (true) {
                ! NomorHp::sah((string) $p->tlp) => 'nomor HP tidak valid',
                AkunPelanggan::dipakaiUserLain((string) $p->tlp) => 'nomor HP dipakai username user staf',
                default => null,
            };
            if ($alasan) {
                $dilewati[] = [$p->id_pelanggan, $p->nama, $p->tlp, $alasan];

                return;
            }
            $akunPelanggan->sinkronkan($p);
            $dibuat++;
        });

    $this->info("{$dibuat} pelanggan dihubungkan ke akun login.");
    if ($dilewati) {
        $this->warn(count($dilewati).' pelanggan dilewati, perbaiki No. telepon-nya lewat Data Pelanggan lalu jalankan lagi:');
        $this->table(['ID', 'Nama', 'Tlp', 'Alasan'], $dilewati);
    }

    return 0;
})->purpose('Buat akun login pelanggan (username & password = nomor HP) untuk pelanggan yang belum punya');

// Catatan audit (menu Logs) lebih tua dari masa simpan dipangkas tiap hari. Butuh cron di server:
// * * * * * cd /var/www/mss-billing && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('model:prune', ['--model' => [AuditLog::class]])->dailyAt('02:00');
