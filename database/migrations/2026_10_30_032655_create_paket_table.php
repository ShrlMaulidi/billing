<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Paket langganan: harga + profile Mikrotik yang dipakai pelanggan (juga tujuan saat internet di-On-kan). */
    public function up(): void
    {
        Schema::create('paket', function (Blueprint $table) {
            $table->id();
            $table->string('nama_paket');
            $table->string('keterangan')->nullable();
            $table->unsignedInteger('harga')->default(0);
            $table->string('jenis', 20)->default('bulanan')->comment('bulanan | non_bulanan');
            $table->string('jns_koneksi', 10)->default('pppoe')->comment('pppoe | hotspot');
            $table->unsignedBigInteger('id_lokasi')->nullable();
            $table->boolean('filter_lokasi')->default(false)->comment('Hanya tampil untuk cabang id_lokasi');
            // null = profile disinkronkan ke semua router
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->string('profile_mikrotik');
            $table->string('rate_limit')->nullable()->comment('rx/tx, mis. 10M/10M');
            $table->string('local_address')->nullable();
            $table->boolean('buat_profile')->default(true);
            $table->boolean('buat_pool')->default(false);
            $table->string('nama_pool')->nullable();
            $table->string('range_pool')->nullable()->comment('mis. 10.10.1.2-10.10.1.254');
            $table->boolean('tampil_registrasi')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paket');
    }
};
