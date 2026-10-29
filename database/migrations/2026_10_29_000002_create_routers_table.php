<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->string('kode_router')->nullable();
            $table->string('nama_router');
            $table->unsignedBigInteger('id_lokasi')->nullable();
            // langsung = IP publik + port API, tunnel = lewat IP router di dalam tunnel
            $table->string('mode_koneksi', 20)->default('langsung');
            $table->string('host')->nullable();
            $table->foreignId('tunnel_id')->nullable()->constrained('tunnels')->restrictOnDelete();
            $table->unsignedInteger('port_api')->default(8728);
            $table->boolean('ssl')->default(false);
            $table->string('username');
            $table->text('password');
            $table->string('profile_isolir')->default('ISOLIR');
            $table->string('status_koneksi', 20)->default('belum_dicek');
            $table->string('identity')->nullable();
            $table->string('versi_routeros')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
