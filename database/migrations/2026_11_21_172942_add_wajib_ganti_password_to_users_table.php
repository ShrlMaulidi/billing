<?php

use App\Services\AkunPelanggan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Akun yang masih memakai password awal (nomor HP) wajib membuat password baru sebelum membuka halaman lain.
 * Akun pelanggan yang sudah ada ikut ditandai karena dibuat dengan password nomor HP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wajib_ganti_password')->default(false)->after('password');
        });

        $rolePelanggan = DB::table('roles')->where(['name' => AkunPelanggan::ROLE, 'guard_name' => 'web'])->value('id');
        if ($rolePelanggan) {
            DB::table('users')->whereIn('id', DB::table('model_has_roles')->where('role_id', $rolePelanggan)->select('model_id'))
                ->update(['wajib_ganti_password' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wajib_ganti_password');
        });
    }
};
