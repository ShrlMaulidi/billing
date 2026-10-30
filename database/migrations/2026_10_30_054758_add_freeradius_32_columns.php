<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Samakan tabel RADIUS dengan skema FreeRADIUS 3.2: tanpa kolom ini query accounting gagal
     * ("Unknown column 'framedinterfaceid'") sehingga sesi tunnel tidak pernah tercatat di radacct.
     */
    public function up(): void
    {
        Schema::table('radacct', function (Blueprint $table) {
            if (! Schema::hasColumn('radacct', 'framedinterfaceid')) {
                $table->string('framedinterfaceid', 44)->default('')->index();
            }
            if (! Schema::hasColumn('radacct', 'class')) {
                $table->string('class', 64)->nullable()->index();
            }
        });

        if (Schema::hasTable('radpostauth') && ! Schema::hasColumn('radpostauth', 'class')) {
            Schema::table('radpostauth', function (Blueprint $table) {
                $table->string('class', 64)->nullable()->index();
            });
        }

        // Dipakai FreeRADIUS saat NAS mengirim Accounting-On/Off
        if (! Schema::hasTable('nasreload')) {
            Schema::create('nasreload', function (Blueprint $table) {
                $table->string('nasipaddress', 15)->primary();
                $table->dateTime('reloadtime');
            });
        }
    }

    /** Tabel dipakai bersama FreeRADIUS, jadi tidak diubah saat rollback. */
    public function down(): void {}
};
