<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateway bisa lebih dari satu per pemilik (perusahaan / cabang Admin Full), dengan satu yang ditandai Utama:
 * Utama perusahaan dipakai semua cabang non Admin Full, Utama cabang Admin Full dipakai pelanggan cabang itu.
 * kunci_utama (unik, terisi hanya pada gateway Utama) menjamin satu Utama per pemilik. Gateway yang sudah ada menjadi Utama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway', function (Blueprint $table) {
            $table->dropUnique(['kunci_pemilik']);
            $table->index('kunci_pemilik');
            $table->string('nama', 60)->nullable()->after('provider');
            $table->boolean('utama')->default(false)->after('status');
            $table->string('kunci_utama', 30)->nullable()->unique()->after('utama')->comment('= kunci_pemilik bila Utama; satu Utama per pemilik');
        });
        DB::table('payment_gateway')->update(['utama' => true, 'kunci_utama' => DB::raw('kunci_pemilik')]);
        DB::table('payment_gateway')->whereNull('nama')->update(['nama' => 'Midtrans']);

    }

    public function down(): void
    {
        Schema::table('payment_gateway', function (Blueprint $table) {
            $table->dropUnique(['kunci_utama']);
            $table->dropColumn(['nama', 'utama', 'kunci_utama']);
            $table->dropIndex(['kunci_pemilik']);
            $table->unique('kunci_pemilik');
        });
    }
};
