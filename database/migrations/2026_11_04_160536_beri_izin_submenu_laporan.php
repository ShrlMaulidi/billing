<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Menu Laporan dipecah per tipe pelanggan. Role yang sudah bisa membuka Laporan mendapat Laporan Mitra & Shafa Net
     * (role Mitra hanya Laporan Mitra); Laporan Korporat & Instansi Pemerintah hanya untuk role yang juga membuka Data Pelanggan.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::where('guard_name', 'web')->with('permissions')->get() as $role) {
            $dimiliki = $role->permissions->pluck('name');
            if (! $dimiliki->contains('laporan.view')) {
                continue;
            }
            $submenu = $role->name === 'Mitra' ? ['mitra'] : ['mitra', 'shafanet'];
            if ($dimiliki->contains('data_pelanggan.view')) {
                array_push($submenu, 'korporat', 'pemerintah');
            }
            $aksi = $dimiliki->contains('laporan.export') ? ['view', 'export'] : ['view'];

            $role->givePermissionTo(collect($submenu)->crossJoin($aksi)
                ->map(fn (array $pasangan) => Permission::findOrCreate("laporan.{$pasangan[0]}.{$pasangan[1]}", 'web'))->all());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin yang sudah diberikan dibiarkan; dicabut lewat menu Hak Akses bila perlu
    }
};
