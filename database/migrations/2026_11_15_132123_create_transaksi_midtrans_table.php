<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transaksi pembayaran online Midtrans (Snap) untuk satu tagihan pelanggan. Pembayaran baru dicatat ke tabel
     * pembayaran setelah Midtrans mengonfirmasi lunas (webhook / cek status), lalu ditautkan lewat pembayaran_id.
     */
    public function up(): void
    {
        Schema::create('transaksi_midtrans', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 50)->unique();
            $table->foreignId('pelanggan_id')->nullable()->constrained('pelanggan')->nullOnDelete();
            $table->date('periode')->comment('Periode pemakaian yang dibayar');
            $table->unsignedInteger('gross_amount');
            $table->string('status', 20)->default('pending')->comment('pending | lunas | lunas_ganda | expire | cancel | deny | failure | selisih');
            $table->string('payment_type', 50)->nullable();
            $table->string('transaction_id', 100)->nullable();
            $table->string('redirect_url', 500)->nullable();
            $table->timestamp('kedaluwarsa_at')->nullable();
            $table->timestamp('dibayar_at')->nullable();
            $table->foreignId('pembayaran_id')->nullable()->constrained('pembayaran')->nullOnDelete();
            $table->string('catatan', 255)->nullable();
            $table->timestamps();

            $table->index(['pelanggan_id', 'periode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_midtrans');
    }
};
