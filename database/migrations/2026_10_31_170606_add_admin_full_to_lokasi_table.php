<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin Full: true = mitra memakai payment gateway sendiri (uang masuk ke mitra, mitra menyetor kewajiban ke perusahaan);
     * false = mitra ikut payment gateway perusahaan dan tidak bisa menambah payment gateway sendiri.
     */
    public function up(): void
    {
        Schema::table('lokasi', function (Blueprint $table) {
            $table->boolean('admin_full')->default(false)->after('terima_cash');
        });
    }

    public function down(): void
    {
        Schema::table('lokasi', function (Blueprint $table) {
            $table->dropColumn('admin_full');
        });
    }
};
