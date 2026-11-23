<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Kirim tagihan via WhatsApp cabang: izin baru pelanggan.belum_lunas.update diberikan ke role yang sudah bisa membuka
 * Pelanggan Belum Lunas. Mitra (pengelola cabang) diberi menu Notifikasi untuk scan WhatsApp & mengatur template
 * cabangnya sendiri (dibatasi per cabang di NotifikasiController). Database baru mendapat izin ini dari HakAksesSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $kirim = Permission::findOrCreate('pelanggan.belum_lunas.update', 'web');
        $notifikasi = [Permission::findOrCreate('kelola_data.notifikasi.view', 'web'), Permission::findOrCreate('kelola_data.notifikasi.update', 'web')];

        Role::where('guard_name', 'web')->whereHas('permissions', fn ($q) => $q->where('name', 'pelanggan.belum_lunas.view'))
            ->get()->each(fn (Role $role) => $role->givePermissionTo($kirim));
        Role::where(['name' => 'Mitra', 'guard_name' => 'web'])->first()?->givePermissionTo($notifikasi);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin yang sudah diberikan dibiarkan; dicabut lewat menu Hak Akses bila perlu
    }
};
