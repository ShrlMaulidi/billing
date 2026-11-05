<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tanggal berhenti berlangganan korporat / instansi: laporan keuangan menagih bulan-bulan sebelum tanggal ini,
     * sehingga tunggakan pelanggan yang sudah berhenti tetap terlihat. Data lama yang sudah berhenti memakai tanggal terakhir diubah.
     */
    public function up(): void
    {
        Schema::table('pelanggan_instansi', function (Blueprint $table) {
            $table->date('tanggal_berhenti')->nullable()->after('tanggal_aktif');
        });
        DB::table('pelanggan_instansi')->where('status', 'berhenti')->update(['tanggal_berhenti' => DB::raw('DATE(updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('pelanggan_instansi', function (Blueprint $table) {
            $table->dropColumn('tanggal_berhenti');
        });
    }
};
