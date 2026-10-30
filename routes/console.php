<?php

use App\Models\Lokasi;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
