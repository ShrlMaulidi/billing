<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Setoran mitra (cabang) ke perusahaan, diinput Super Admin. Menambah saldo mitra pada periode kewajibannya.
     * Bukti setor (opsional) disimpan di disk privat (storage/app/private).
     */
    public function up(): void
    {
        Schema::create('setoran_mitra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_lokasi')->constrained('lokasi')->restrictOnDelete();
            $table->date('periode')->comment('Tanggal 1 bulan kewajiban yang disetor');
            $table->unsignedInteger('nominal');
            $table->string('metode', 20)->comment('cash | transfer');
            $table->foreignId('rekening_id')->nullable()->comment('Rekening pusat tujuan transfer')->constrained('rekening')->restrictOnDelete();
            $table->string('bukti_setor')->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->foreignId('user_id')->nullable()->comment('Petugas yang menginput')->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['id_lokasi', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setoran_mitra');
    }
};
