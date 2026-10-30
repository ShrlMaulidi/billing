<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Cabang / lokasi. Tabel lain merujuk lewat kolom id_lokasi. */
    public function up(): void
    {
        Schema::create('lokasi', function (Blueprint $table) {
            $table->id();
            $table->string('kode_lokasi', 20)->unique();
            $table->string('nama_lokasi');
            $table->text('alamat_lokasi')->nullable();
            $table->string('tlp_lokasi', 30)->nullable();
            $table->string('tlp_owner', 30)->nullable();
            // User ber-role Mitra yang mengelola cabang
            $table->foreignId('id_mitra')->nullable()->constrained('users')->nullOnDelete();
            $table->string('group_wa')->nullable()->comment('ID grup WhatsApp notifikasi, mis. 1203630xxxx@g.us');
            $table->boolean('terima_cash')->default(true);
            $table->string('status', 20)->default('aktif')->comment('aktif | tidak_aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lokasi');
    }
};
