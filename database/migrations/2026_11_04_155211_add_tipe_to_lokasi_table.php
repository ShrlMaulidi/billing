<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipe pelanggan untuk cabang / lokasi: reseller (mitra menjual kembali ke end user) atau
     * retail (produk ISP sendiri, Shafa.net, pelanggannya dikelola langsung). Cabang lama dianggap reseller.
     */
    public function up(): void
    {
        Schema::table('lokasi', function (Blueprint $table) {
            $table->string('tipe', 20)->default('reseller')->after('id')->comment('reseller | retail');
        });
    }

    public function down(): void
    {
        Schema::table('lokasi', function (Blueprint $table) {
            $table->dropColumn('tipe');
        });
    }
};
