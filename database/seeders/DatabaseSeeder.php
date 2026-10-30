<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\HakAkses;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(HakAksesSeeder::class);

        // Akun login untuk uji coba. Di server, ganti kata sandinya: php artisan user:create sahrul
        User::updateOrCreate(['email' => 'sahrul1@gmail.com'], [
            'name' => 'Sahrul Maulidi',
            'username' => 'sahrul',
            'password' => 'sahrul123',
        ])->syncRoles(HakAkses::SUPER_ADMIN);
    }
}
