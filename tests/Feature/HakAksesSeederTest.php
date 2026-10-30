<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HakAkses;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HakAksesSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mulai tanpa role & permission, seperti `php artisan db:seed` pertama kali. Dihapus di dalam transaksi test
     * (bukan lewat $seeder) karena database hasil migrasi dipakai bersama seluruh suite.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Role::query()->delete();
        Permission::query()->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_database_seeder_creates_roles_with_default_permissions_on_empty_database(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(HakAkses::bawaan('Sales'), Role::findByName('Sales')->permissions->pluck('name')->all());
        $this->assertTrue(User::firstWhere('username', 'sahrul')->isSuperAdmin());
    }

    /** Database lama: migrasi sudah memindahkan role Super Admin, permission belum ada sama sekali. */
    public function test_database_seeder_works_when_roles_were_migrated_before_permissions_exist(): void
    {
        Role::create(['name' => HakAkses::SUPER_ADMIN, 'guard_name' => 'web']);

        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(HakAkses::bawaan('Sales'), Role::findByName('Sales')->permissions->pluck('name')->all());
        $this->assertTrue(User::firstWhere('username', 'sahrul')->isSuperAdmin());
    }
}
