<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Area di dalam cabang; satu cabang bisa punya banyak area. */
    public function up(): void
    {
        Schema::create('area', function (Blueprint $table) {
            $table->id();
            $table->string('kode_area', 30)->nullable();
            $table->string('nama_area');
            $table->foreignId('id_lokasi')->constrained('lokasi')->cascadeOnDelete();
            $table->text('cakupan')->nullable();
            $table->string('status', 20)->default('aktif')->comment('aktif | tidak_aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area');
    }
};
