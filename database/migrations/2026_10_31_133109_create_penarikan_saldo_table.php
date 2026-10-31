<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengajuan penarikan saldo mitra (cabang) dari saldo satu periode. Saldo baru berkurang setelah disetujui Super Admin.
     * Rekening penerima disimpan per pengajuan agar riwayat transfer tetap utuh walau rekening mitra berganti.
     */
    public function up(): void
    {
        Schema::create('penarikan_saldo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_lokasi')->constrained('lokasi')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->comment('Pemohon')->constrained('users')->nullOnDelete();
            $table->string('nama_request', 100);
            $table->date('periode_sumber')->comment('Tanggal 1 bulan saldo yang ditarik');
            $table->unsignedInteger('nominal');
            $table->string('keterangan', 500);
            $table->string('bank_penerima', 100);
            $table->string('no_rekening_penerima', 50);
            $table->string('atas_nama_penerima', 100);
            $table->string('status', 20)->default('pending')->comment('pending | disetujui | ditolak');
            $table->foreignId('diproses_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('diproses_at')->nullable();
            $table->string('catatan', 255)->nullable()->comment('Alasan penolakan / catatan persetujuan');
            $table->timestamps();

            $table->index(['id_lokasi', 'periode_sumber', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penarikan_saldo');
    }
};
