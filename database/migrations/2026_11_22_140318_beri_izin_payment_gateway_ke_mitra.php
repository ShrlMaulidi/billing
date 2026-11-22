<?php

use App\Support\HakAkses;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Cabang Admin Full mengelola payment gateway-nya sendiri dari dashboard cabang, jadi role Mitra yang sudah ada diberi
     * izin menu Payment Gateway. Server tetap menolak cabang non Admin Full menambah gateway (lihat PaymentGatewayController);
     * mereka hanya melihat keterangan gateway Utama perusahaan. Database baru mendapat izin ini dari HakAksesSeeder.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $mitra = Role::where(['name' => 'Mitra', 'guard_name' => 'web'])->first();
        $mitra?->givePermissionTo(collect(HakAkses::MENU['payment_gateway'][2])->map(fn (string $aksi) => Permission::findOrCreate("payment_gateway.$aksi", 'web'))->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin yang sudah diberikan dibiarkan; dicabut lewat menu Hak Akses bila perlu
    }
};
