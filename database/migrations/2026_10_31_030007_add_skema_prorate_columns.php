<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skema tagihan pelanggan: full (langsung harga penuh) atau prorate (bulan registrasi dihitung per hari).
     * Pembayaran prorate ditandai agar terbaca di riwayat & kwitansi.
     */
    public function up(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->string('skema_tagihan', 10)->default('full')->after('jns_tagihan')->comment('full | prorate');
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->boolean('is_prorate')->default(false)->after('harga');
        });
    }

    public function down(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropColumn('is_prorate');
        });
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropColumn('skema_tagihan');
        });
    }
};
