<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak audit (menu Logs): siapa melakukan apa, kapan, dari mana. Nama, role, dan cabang pelaku disimpan sebagai salinan
     * agar catatan tetap utuh walau akun user diubah atau dihapus. Catatan tidak bisa diubah/dihapus dari aplikasi;
     * hanya dipangkas otomatis setelah masa simpan (AuditLog::MASA_SIMPAN_HARI).
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_nama', 100)->nullable();
            $table->string('user_role', 50)->nullable();
            $table->unsignedBigInteger('id_lokasi')->nullable()->index()->comment('Cabang pelaku saat aksi (null = pusat)');
            $table->string('modul', 50);
            $table->string('aksi', 20);
            $table->string('deskripsi', 500);
            $table->string('subjek_type', 100)->nullable();
            $table->unsignedBigInteger('subjek_id')->nullable();
            $table->json('perubahan')->nullable()->comment('{kolom: {dari, ke}}; nilai rahasia disamarkan');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->index(['modul', 'aksi']);
            $table->index(['subjek_type', 'subjek_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
