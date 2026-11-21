<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiket keluhan/permintaan dari pelanggan (halaman Akun Saya), ditangani staf di menu Tiket Pelanggan.
 * id_lokasi = cabang pelanggan saat tiket dibuat, untuk membatasi tiket yang terlihat user cabang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiket', function (Blueprint $table) {
            $table->id();
            $table->string('no_tiket', 30)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pelanggan_id')->nullable()->constrained('pelanggan')->nullOnDelete();
            $table->foreignId('id_lokasi')->nullable()->constrained('lokasi')->nullOnDelete();
            $table->string('kategori', 30)->comment('gangguan | tagihan | paket | lainnya');
            $table->string('judul', 150);
            $table->string('status', 20)->default('baru')->comment('baru | diproses | selesai');
            $table->timestamp('selesai_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id_lokasi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiket');
    }
};
