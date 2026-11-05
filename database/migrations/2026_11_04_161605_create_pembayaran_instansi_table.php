<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pembayaran masuk dari pelanggan korporat & instansi pemerintah (menu Transaksi › Pembayaran Masuk).
     * PPN dan total dihitung server dari nominal × ppn_persen.
     */
    public function up(): void
    {
        Schema::create('pembayaran_instansi', function (Blueprint $table) {
            $table->id();
            // Restrict: pelanggan yang sudah punya pembayaran tidak boleh terhapus tanpa jejak
            $table->foreignId('pelanggan_instansi_id')->constrained('pelanggan_instansi')->restrictOnDelete();
            $table->date('periode')->comment('Tanggal 1 bulan yang dibayar');
            $table->unsignedBigInteger('nominal')->comment('Sebelum PPN');
            $table->decimal('ppn_persen', 5, 2)->default(0);
            $table->unsignedBigInteger('ppn');
            $table->unsignedBigInteger('total')->comment('nominal + ppn');
            $table->foreignId('user_id')->nullable()->comment('Petugas yang menginput')->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pelanggan_instansi_id', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran_instansi');
    }
};
