<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Akun Midtrans tempat transaksi dibuat; null = konfigurasi .env (transaksi lama). Notifikasi & cek status memakai akun ini. */
    public function up(): void
    {
        Schema::table('transaksi_midtrans', function (Blueprint $table) {
            $table->foreignId('payment_gateway_id')->nullable()->after('pelanggan_id')->constrained('payment_gateway')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_midtrans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_gateway_id');
        });
    }
};
