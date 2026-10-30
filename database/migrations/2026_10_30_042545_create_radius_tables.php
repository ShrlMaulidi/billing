<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel FreeRADIUS (skema standar rlm_sql) untuk akun tunnel L2TP.
     * Di server tabel ini sudah dibuat bersama FreeRADIUS, jadi hanya dibuat bila belum ada (lokal & test).
     */
    public function up(): void
    {
        foreach (['radcheck', 'radreply'] as $nama) {
            if (! Schema::hasTable($nama)) {
                Schema::create($nama, function (Blueprint $table) {
                    $table->increments('id');
                    $table->string('username', 64)->default('')->index();
                    $table->string('attribute', 64)->default('');
                    $table->char('op', 2)->default('==');
                    $table->string('value', 253)->default('');
                });
            }
        }

        if (! Schema::hasTable('radacct')) {
            Schema::create('radacct', function (Blueprint $table) {
                $table->bigIncrements('radacctid');
                $table->string('acctsessionid', 64)->default('');
                $table->string('acctuniqueid', 32)->default('')->unique();
                $table->string('username', 64)->default('')->index();
                $table->string('realm', 64)->nullable()->default('');
                $table->string('nasipaddress', 15)->default('');
                $table->string('nasportid', 32)->nullable();
                $table->string('nasporttype', 32)->nullable();
                $table->dateTime('acctstarttime')->nullable();
                $table->dateTime('acctupdatetime')->nullable();
                $table->dateTime('acctstoptime')->nullable()->index();
                $table->integer('acctinterval')->nullable();
                $table->unsignedInteger('acctsessiontime')->nullable();
                $table->string('acctauthentic', 32)->nullable();
                $table->string('connectinfo_start', 128)->nullable();
                $table->string('connectinfo_stop', 128)->nullable();
                $table->bigInteger('acctinputoctets')->nullable();
                $table->bigInteger('acctoutputoctets')->nullable();
                $table->string('calledstationid', 50)->default('');
                $table->string('callingstationid', 50)->default('');
                $table->string('acctterminatecause', 32)->default('');
                $table->string('servicetype', 32)->nullable();
                $table->string('framedprotocol', 32)->nullable();
                $table->string('framedipaddress', 15)->default('');
                $table->string('framedipv6address', 45)->default('');
                $table->string('framedipv6prefix', 45)->default('');
                $table->string('framedinterface', 44)->default('');
                $table->string('delegatedipv6prefix', 45)->default('');
            });
        }
    }

    /** Tabel dipakai bersama FreeRADIUS, jadi tidak dihapus saat rollback. */
    public function down(): void {}
};
