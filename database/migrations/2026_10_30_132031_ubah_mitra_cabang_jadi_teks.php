<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mitra / pemilik cabang diisi teks bebas. Akun user dibuat setelah cabang ada,
     * dan cabangnya dipilih di Manajemen User (users.id_lokasi).
     */
    public function up(): void
    {
        Schema::table('lokasi', function (Blueprint $table) {
            $table->string('nama_mitra')->nullable()->after('tlp_owner');
        });

        // Pertahankan nama mitra yang sebelumnya dipilih dari daftar user
        DB::table('lokasi')->whereNotNull('id_mitra')->orderBy('id')->each(function (object $lokasi) {
            DB::table('lokasi')->where('id', $lokasi->id)->update(['nama_mitra' => DB::table('users')->where('id', $lokasi->id_mitra)->value('name')]);
        });

        Schema::table('lokasi', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_mitra');
        });
    }

    public function down(): void
    {
        Schema::table('lokasi', function (Blueprint $table) {
            $table->foreignId('id_mitra')->nullable()->after('tlp_owner')->constrained('users')->nullOnDelete();
            $table->dropColumn('nama_mitra');
        });
    }
};
