<?php

namespace App\Http\Controllers;

use App\Support\HakAkses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

/**
 * Matriks permission per role (spatie/laravel-permission). Role Super Admin terkunci (akses penuh),
 * dan role milik sendiri tidak bisa diubah agar user tidak menaikkan hak aksesnya sendiri.
 */
class HakAksesController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::withCount('users')->orderBy('id')->get();
        $dipilih = $roles->firstWhere('id', $request->integer('role')) ?? $roles->first();

        // {menu: {aksi: true}} untuk role terpilih
        $permissions = [];
        foreach ($dipilih?->permissions->pluck('name') ?? [] as $nama) {
            $aksi = substr($nama, strrpos($nama, '.') + 1);
            $permissions[substr($nama, 0, strrpos($nama, '.'))][$aksi] = true;
        }

        return Inertia::render('User/HakAkses', [
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id, 'nama' => $r->name, 'keterangan' => $r->keterangan,
                'is_locked' => $r->name === HakAkses::SUPER_ADMIN, 'jumlah_user' => $r->users_count,
            ]),
            'selected' => $dipilih?->id,
            'menus' => collect(HakAkses::MENU)->map(fn ($m, $key) => ['key' => $key, 'label' => $m[0], 'aksi' => $m[2]])->values(),
            'permissions' => (object) $permissions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:50', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ], $this->pesanValidasi(), ['nama' => 'Nama role', 'keterangan' => 'Keterangan']);
        $role = Role::create(['name' => $data['nama'], 'guard_name' => 'web', 'keterangan' => $data['keterangan'] ?? null]);

        return response()->json(['message' => "Role {$role->name} ditambahkan", 'id' => $role->id]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->pastikanBolehDiubah($request, $role);
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(HakAkses::permissions())],
        ], $this->pesanValidasi(), ['permissions' => 'Permission', 'permissions.*' => 'Permission']);
        $role->syncPermissions($data['permissions']);

        return response()->json(['message' => "Hak akses role {$role->name} disimpan"]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->pastikanBolehDiubah($request, $role);
        if ($role->users()->exists()) {
            return response()->json(['message' => "Role {$role->name} masih dipakai user, ganti role user tersebut dulu"], 422);
        }
        $role->delete();

        return response()->json(['message' => "Role {$role->name} dihapus"]);
    }

    private function pastikanBolehDiubah(Request $request, Role $role): void
    {
        abort_if($role->name === HakAkses::SUPER_ADMIN, 422, 'Role Super Admin terkunci dan tidak dapat diubah');
        abort_if(! $request->user()->isSuperAdmin() && $request->user()->hasRole($role), 422, 'Tidak bisa mengubah hak akses role Anda sendiri');
    }
}
