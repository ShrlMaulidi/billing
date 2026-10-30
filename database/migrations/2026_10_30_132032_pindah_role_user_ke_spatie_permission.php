<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MODEL_USER = 'App\Models\User';

    /**
     * Role user pindah dari kolom teks users.role ke tabel role spatie/laravel-permission,
     * plus data akun untuk Manajemen User (cabang, status, login terakhir).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tlp', 30)->nullable()->after('email');
            // null = boleh mengakses semua cabang. Restrict: cabang terhapus tidak boleh membuat user-nya jadi semua cabang
            $table->foreignId('id_lokasi')->nullable()->after('tlp')->constrained('lokasi')->restrictOnDelete();
            $table->string('status', 20)->default('aktif')->after('id_lokasi')->comment('aktif | nonaktif');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->string('keterangan')->nullable()->after('guard_name');
        });

        $now = now();
        foreach (DB::table('users')->whereNotNull('role')->get(['id', 'role']) as $user) {
            $roleId = DB::table('roles')->where(['name' => $user->role, 'guard_name' => 'web'])->value('id')
                ?? DB::table('roles')->insertGetId(['name' => $user->role, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => self::MODEL_USER, 'model_id' => $user->id]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('Super Admin')->after('username');
        });
        $roles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_type', self::MODEL_USER)->pluck('roles.name', 'model_id');
        foreach ($roles as $userId => $role) {
            DB::table('users')->where('id', $userId)->update(['role' => $role]);
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('keterangan');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_lokasi');
            $table->dropColumn(['tlp', 'status', 'last_login_at', 'last_login_ip']);
        });
    }
};
