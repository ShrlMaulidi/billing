<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Rekening bank / e-wallet tujuan pembayaran transfer (menu Kelola Data › Rekening). */
    public function up(): void
    {
        Schema::create('rekening', function (Blueprint $table) {
            $table->id();
            $table->string('nama_rekening', 100)->comment('Nama bank / e-wallet');
            $table->string('atas_nama', 100);
            $table->string('no_rekening', 50);
            $table->foreignId('id_lokasi')->nullable()->comment('null = berlaku untuk semua cabang')->constrained('lokasi')->restrictOnDelete();
            $table->boolean('tampil_invoice')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekening');
    }
};
