<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Tambah cabang pindah ke menu Data Pelanggan. Role yang sebelumnya boleh menambah cabang mendapat izin
     * Data Pelanggan yang setara, agar aksesnya tidak hilang setelah update.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $setara = [
            'kelola_data.cabang.create' => ['data_pelanggan.view', 'data_pelanggan.create'],
            'kelola_data.cabang.update' => ['data_pelanggan.update'],
            'kelola_data.cabang.delete' => ['data_pelanggan.delete'],
        ];

        foreach (Role::where('guard_name', 'web')->with('permissions')->get() as $role) {
            $dimiliki = $role->permissions->pluck('name');
            if (! $dimiliki->contains('kelola_data.cabang.create')) {
                continue;
            }
            foreach ($setara as $lama => $baru) {
                if ($dimiliki->contains($lama)) {
                    $role->givePermissionTo(array_map(fn (string $nama) => Permission::findOrCreate($nama, 'web'), $baru));
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin yang sudah diberikan dibiarkan; dicabut lewat menu Hak Akses bila perlu
    }
};
