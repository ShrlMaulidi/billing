<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis tagihan pelanggan saat membayar, untuk menampilkan periode pembayaran di samping periode pemakaian
     * (pascabayar: pembayaran Oktober untuk pemakaian September). Pembayaran lama diisi dari data pelanggannya.
     */
    public function up(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->string('jns_tagihan', 20)->default('prabayar')->after('periode')->comment('prabayar | pascabayar');
        });

        DB::table('pembayaran')->whereIn('pelanggan_id', DB::table('pelanggan')->where('jns_tagihan', 'pascabayar')->select('id'))
            ->update(['jns_tagihan' => 'pascabayar']);
    }

    public function down(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropColumn('jns_tagihan');
        });
    }
};
