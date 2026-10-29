<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Data pelanggan diisi sendiri oleh user + riwayat On/Off (isolir) per pelanggan. */
    public function up(): void
    {
        Schema::create('pelanggan', function (Blueprint $table) {
            $table->id();
            $table->string('id_pelanggan')->nullable()->unique();
            $table->string('nama');
            $table->string('tlp')->nullable();
            $table->text('alamat')->nullable();
            $table->unsignedBigInteger('id_lokasi')->nullable();
            $table->unsignedBigInteger('id_paket')->nullable();
            // Router dihapus → pelanggan tetap ada, hanya kehilangan router-nya
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->string('username_pppoe');
            $table->date('tgl_registrasi')->nullable();
            $table->unsignedTinyInteger('tgl_jatuh_tempo')->nullable();
            $table->string('status', 20)->default('aktif')->comment('aktif | berhenti');
            $table->string('status_langganan', 10)->default('on')->comment('on | off (terisolir)');
            $table->string('profile_asal')->nullable()->comment('Profile sebelum diisolir, dipakai saat diaktifkan kembali');
            $table->timestamp('isolir_at')->nullable();
            $table->timestamps();
            $table->unique(['router_id', 'username_pppoe']);
        });

        Schema::create('isolir_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->foreignId('pelanggan_id')->nullable()->constrained('pelanggan')->nullOnDelete();
            $table->string('username');
            $table->string('aksi', 20)->comment('isolir | aktifkan');
            $table->string('profile_dari')->nullable();
            $table->string('profile_ke')->nullable();
            $table->boolean('berhasil');
            $table->text('pesan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isolir_logs');
        Schema::dropIfExists('pelanggan');
    }
};
