<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment gateway yang diatur sendiri dari menu Payment Gateway (tanpa mengubah .env). Satu gateway per pemilik:
     * perusahaan (id_lokasi null, dipakai cabang non Admin Full) atau satu cabang Admin Full. Kredensial terenkripsi APP_KEY.
     */
    public function up(): void
    {
        Schema::create('payment_gateway', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->foreignId('id_lokasi')->nullable()->constrained('lokasi')->cascadeOnDelete();
            $table->string('kunci_pemilik', 30)->unique()->comment('perusahaan | lokasi:{id}; menjamin satu gateway per pemilik');
            $table->boolean('produksi')->default(false);
            $table->text('kredensial')->comment('JSON terenkripsi (APP_KEY), tidak pernah dikirim ke browser');
            $table->string('status', 20)->default('aktif');
            $table->timestamp('diuji_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway');
    }
};
