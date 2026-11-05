<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sales yang membawa pelanggan korporat / instansi (user ber-role Sales), untuk memantau pembayaran pelanggannya. */
    public function up(): void
    {
        Schema::table('pelanggan_instansi', function (Blueprint $table) {
            $table->foreignId('sales_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pelanggan_instansi', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_id');
        });
    }
};
