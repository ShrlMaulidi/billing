<?php

use App\Services\AkunPelanggan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Akun login pelanggan (role Pelanggan, username & password awal = nomor HP). Beberapa pelanggan bernomor HP sama
 * memakai satu akun. Akun untuk pelanggan lama dibuat lewat: php artisan pelanggan:buat-akun
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('sales_id')->constrained('users')->nullOnDelete();
        });

        Role::findOrCreate(AkunPelanggan::ROLE, 'web')->update(['keterangan' => AkunPelanggan::KETERANGAN_ROLE]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
