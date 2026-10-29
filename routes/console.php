<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Buat user login baru, atau reset kata sandi bila username sudah ada.
// Kata sandi diketik tersembunyi (tidak tersimpan di riwayat terminal).
Artisan::command('user:create {username} {--name= : Nama tampilan} {--role=Super Admin : Role user}', function (string $username) {
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
        User::create(['username' => $username, 'name' => $this->option('name') ?: $username, 'role' => $this->option('role'), 'password' => $password]);
        $this->info("User {$username} berhasil dibuat. Silakan login.");
    }

    return 0;
})->purpose('Buat user login atau reset kata sandinya');
