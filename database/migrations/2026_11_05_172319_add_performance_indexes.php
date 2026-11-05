<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indeks untuk filter yang dipakai hampir di setiap halaman: pelanggan per cabang & status (daftar pelanggan, dashboard,
     * rekap cabang, laporan), pelanggan per paket, dan pembayaran per tanggal bayar tanpa filter cabang (dashboard, income summary).
     */
    public function up(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->index(['id_lokasi', 'status']);
            $table->index('id_paket');
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropIndex(['id_lokasi', 'status']);
            $table->dropIndex(['id_paket']);
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
        });
    }
};
