<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pelanggan langsung berbentuk lembaga: korporat (PT, sekolah & rumah sakit swasta) dan instansi pemerintah
     * (dinas, sekolah negeri, RSUD). Tidak terikat cabang; dikelola di menu Data Pelanggan.
     */
    public function up(): void
    {
        Schema::create('pelanggan_instansi', function (Blueprint $table) {
            $table->id();
            $table->string('tipe', 20)->comment('korporat | pemerintah');
            $table->string('nama');
            $table->text('alamat');
            $table->string('kapasitas', 50)->comment('mis. 100 Mbps');
            $table->unsignedInteger('harga')->default(0);
            $table->string('metro', 100)->nullable()->comment('khusus korporat');
            $table->string('nik', 16)->nullable()->comment('khusus korporat');
            $table->string('npwp', 16)->nullable();
            // Path di disk privat "local"; dibuka lewat route yang memeriksa hak akses
            $table->string('file_ktp')->nullable();
            $table->string('file_npwp')->nullable();
            $table->date('tanggal_aktif');
            $table->string('jns_tagihan', 20)->default('prabayar')->comment('prabayar | pascabayar');
            $table->string('status', 20)->default('berlangganan')->comment('berlangganan | berhenti');
            $table->timestamps();

            $table->index('tipe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pelanggan_instansi');
    }
};
