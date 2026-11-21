<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Role yang menerima tiket pelanggan di cabangnya (lihat HakAkses::bawaan). */
    private const ROLE = ['Admin', 'Mitra', 'Teknisi'];

    /**
     * Menu Tiket baru: tiket dari dashboard pelanggan diterima cabang masing-masing, jadi role bawaan yang sudah ada
     * (Admin, Mitra pemilik cabang, Teknisi) langsung diberi izin lihat & balas. Izin role lain tidak diubah.
     * Database baru mendapat izin ini dari HakAksesSeeder.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $izin = [Permission::findOrCreate('tiket.view', 'web'), Permission::findOrCreate('tiket.update', 'web')];

        foreach (Role::where('guard_name', 'web')->whereIn('name', self::ROLE)->get() as $role) {
            $role->givePermissionTo($izin);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin yang sudah diberikan dibiarkan; dicabut lewat menu Hak Akses bila perlu
    }
};
