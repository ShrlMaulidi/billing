<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pembayaran tagihan bulanan pelanggan; satu pelanggan satu pembayaran per periode.
     * Nama pelanggan & paket disimpan ulang agar riwayat keuangan tetap utuh walau datanya berubah atau dihapus.
     */
    public function up(): void
    {
        Schema::create('pembayaran', function (Blueprint $table) {
            $table->id();
            $table->string('no_invoice', 30)->nullable()->unique();
            $table->foreignId('pelanggan_id')->nullable()->constrained('pelanggan')->nullOnDelete();
            $table->foreignId('id_lokasi')->nullable()->constrained('lokasi')->restrictOnDelete();
            $table->date('periode')->comment('Tanggal 1 bulan tagihan yang dibayar');
            $table->string('nama_pelanggan');
            $table->string('nama_paket');
            $table->unsignedInteger('harga')->comment('Harga paket saat dibayar');
            $table->string('diskon_tipe', 20)->nullable()->comment('nominal | persentase');
            $table->decimal('diskon_nilai', 15, 2)->default(0)->comment('Nilai yang diinput: Rupiah atau persen');
            $table->unsignedInteger('diskon')->default(0)->comment('Potongan dalam Rupiah');
            $table->unsignedInteger('jml_bayar');
            $table->string('metode', 20)->comment('cash | transfer');
            $table->foreignId('rekening_id')->nullable()->constrained('rekening')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->comment('Petugas yang menginput')->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at');
            $table->timestamps();

            $table->unique(['pelanggan_id', 'periode']);
            $table->index(['id_lokasi', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran');
    }
};
