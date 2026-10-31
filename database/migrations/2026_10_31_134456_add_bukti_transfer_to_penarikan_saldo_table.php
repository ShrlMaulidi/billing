<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Foto bukti transfer wajib diunggah Super Admin saat menyetujui penarikan; path di disk privat (storage/app/private). */
    public function up(): void
    {
        Schema::table('penarikan_saldo', function (Blueprint $table) {
            $table->string('bukti_transfer')->nullable()->after('catatan');
        });
    }

    public function down(): void
    {
        Schema::table('penarikan_saldo', function (Blueprint $table) {
            $table->dropColumn('bukti_transfer');
        });
    }
};
