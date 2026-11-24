<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Submenu baru Laporan › Dashboard Laporan (Mitra, Instansi & Korporat yang sudah / belum bayar, khusus user pusat):
 * diberikan ke role yang sudah bisa membuka Laporan Korporat atau Laporan Instansi Pemerintah (laporan tingkat pusat).
 * Database baru mendapat izin ini dari HakAksesSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $izin = Permission::findOrCreate('laporan.dashboard.view', 'web');

        Role::where('guard_name', 'web')
            ->whereHas('permissions', fn ($q) => $q->whereIn('name', ['laporan.korporat.view', 'laporan.pemerintah.view']))
            ->get()->each(fn (Role $role) => $role->givePermissionTo($izin));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin yang sudah diberikan dibiarkan; dicabut lewat menu Hak Akses bila perlu
    }
};
