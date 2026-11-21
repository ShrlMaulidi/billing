<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Percakapan dalam satu tiket: pesan pelanggan dan balasan staf. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiket_pesan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiket_id')->constrained('tiket')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('dari_pelanggan')->default(true);
            $table->text('pesan');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiket_pesan');
    }
};
