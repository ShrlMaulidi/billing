<?php

namespace Database\Seeders;

use App\Support\HakAkses;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Daftar permission & role bawaan. Aman dijalankan berulang (dipanggil deploy.sh): permission baru ditambahkan,
 * role yang sudah ada tidak diubah agar pengaturan dari menu Hak Akses tidak tertimpa.
 */
class HakAksesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (HakAkses::permissions() as $nama) {
            Permission::findOrCreate($nama, 'web');
        }
        // DatabaseSeeder mematikan event model (WithoutModelEvents), sehingga cache spatie tidak ikut
        // dikosongkan saat permission dibuat; tanpa ini syncPermissions tidak menemukan permission baru.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (HakAkses::ROLE as $nama => $keterangan) {
            if (Role::where(['name' => $nama, 'guard_name' => 'web'])->exists()) {
                continue;
            }
            // Satu transaksi: bila pemberian permission gagal, role tidak tertinggal tanpa permission
            DB::transaction(fn () => Role::create(['name' => $nama, 'guard_name' => 'web', 'keterangan' => $keterangan])
                ->syncPermissions(HakAkses::bawaan($nama)));
        }
    }
}
