<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan setiap pesan WhatsApp yang dikirim lewat WhatsApp cabang (terkirim / gagal beserta alasannya). Dipakai penanda
 * "sudah dikirim" di Pelanggan Belum Lunas dan hitungan di kartu Data WAGW dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesan_wa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_lokasi')->constrained('lokasi')->cascadeOnDelete();
            $table->foreignId('pelanggan_id')->nullable()->constrained('pelanggan')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Pengirim');
            $table->string('jenis', 30)->comment('tagihan | uji');
            $table->date('periode')->nullable()->comment('Bulan tagihan (Y-m-01) untuk pesan tagihan');
            $table->string('nomor', 20);
            $table->text('isi');
            $table->string('status', 20)->comment('terkirim | gagal');
            $table->string('keterangan')->nullable()->comment('Alasan gagal');
            $table->string('wa_id', 100)->nullable();
            $table->timestamps();
            $table->index(['pelanggan_id', 'periode', 'status']);
            $table->index(['id_lokasi', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesan_wa');
    }
};
