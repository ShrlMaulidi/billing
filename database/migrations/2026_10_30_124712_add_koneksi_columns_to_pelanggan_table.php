<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data pelanggan tambahan + jenis koneksi. username_pppoe kini juga dipakai untuk username Hotspot,
     * dan boleh kosong untuk koneksi Static (pakai ip_address) dan IP Binding (pakai mac_address).
     */
    public function up(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->string('nik', 16)->nullable()->after('tlp');
            $table->string('jns_tagihan', 20)->default('prabayar')->after('alamat')->comment('prabayar | pascabayar');
            $table->foreignId('id_area')->nullable()->after('id_lokasi')->constrained('area')->nullOnDelete();
            $table->string('jns_koneksi', 20)->default('pppoe')->after('router_id')->comment('pppoe | hotspot | static | ip_binding');
            $table->string('ip_address', 45)->nullable()->after('username_pppoe');
            $table->string('mac_address', 17)->nullable()->after('ip_address');
            $table->boolean('ppn')->default(false)->after('tgl_jatuh_tempo');
            $table->foreignId('sales_id')->nullable()->after('ppn')->constrained('users')->nullOnDelete();
            $table->string('username_pppoe')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_area');
            $table->dropConstrainedForeignId('sales_id');
            $table->dropColumn(['nik', 'jns_tagihan', 'jns_koneksi', 'ip_address', 'mac_address', 'ppn']);
        });
    }
};
