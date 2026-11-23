<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template kalimat pesan WhatsApp per cabang (diatur di menu Notifikasi). Cabang tanpa template memakai kalimat bawaan
 * (App\Models\TemplatePesan::BAWAAN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_pesan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_lokasi')->constrained('lokasi')->cascadeOnDelete();
            $table->string('jenis', 30)->default('tagihan')->comment('tagihan');
            $table->text('isi');
            $table->timestamps();
            $table->unique(['id_lokasi', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_pesan');
    }
};
