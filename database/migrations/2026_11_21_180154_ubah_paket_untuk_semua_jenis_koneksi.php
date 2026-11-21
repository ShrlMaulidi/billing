<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paket bisa untuk semua jenis koneksi pelanggan (PPPoE, Hotspot, Static, IP Binding).
 * Paket Static & IP Binding tidak memakai profile MikroTik, jadi profile_mikrotik boleh kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paket', function (Blueprint $table) {
            $table->string('jns_koneksi', 10)->default('pppoe')->comment('pppoe | hotspot | static | ip_binding')->change();
            $table->string('profile_mikrotik')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('paket', function (Blueprint $table) {
            $table->string('jns_koneksi', 10)->default('pppoe')->comment('pppoe | hotspot')->change();
            $table->string('profile_mikrotik')->nullable(false)->change();
        });
    }
};
