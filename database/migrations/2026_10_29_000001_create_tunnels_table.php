<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tunnel VPN untuk menjangkau router turunan yang hanya punya IP private
        Schema::create('tunnels', function (Blueprint $table) {
            $table->id();
            $table->string('nama_tunnel');
            $table->string('protokol', 10)->default('L2TP');
            $table->string('server')->comment('Alamat server tunnel yang di-dial router');
            $table->string('username');
            $table->text('password');
            $table->string('ip_tunnel')->comment('IP router di dalam tunnel, tujuan koneksi API');
            $table->string('status', 20)->default('aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tunnels');
    }
};
