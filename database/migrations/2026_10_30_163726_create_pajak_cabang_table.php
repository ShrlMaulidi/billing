<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tarif pajak & bagi hasil cabang per bulan (menu Kelola Data › Pengaturan Pajak). */
    public function up(): void
    {
        Schema::create('pajak_cabang', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_lokasi')->constrained('lokasi')->cascadeOnDelete();
            $table->date('periode')->comment('Tanggal 1 bulan berlaku');
            $table->decimal('ppn_persen', 5, 2)->default(0);
            $table->decimal('pph_persen', 5, 2)->default(0);
            $table->decimal('bhp_persen', 5, 2)->default(0);
            $table->decimal('uso_persen', 5, 2)->default(0);
            $table->string('admin_fee_tipe', 20)->default('nominal')->comment('persentase | nominal');
            $table->decimal('admin_fee', 15, 2)->default(0);
            $table->string('metode_bagi_hasil', 20)->default('persentase')->comment('persentase | nominal');
            $table->decimal('nilai_bagi_hasil', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['id_lokasi', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pajak_cabang');
    }
};
