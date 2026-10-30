<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\HakAkses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

/**
 * Akun user: role (spatie) + cabang yang boleh diakses (kosong = semua cabang).
 * Pengaman eskalasi hak akses: hanya Super Admin yang bisa membuat/mengubah Super Admin,
 * dan user tidak bisa mengganti role atau menonaktifkan akunnya sendiri.
 */
class UserController extends Controller
{
    public function index()
    {
        return Inertia::render('User/Manajemen', [
            'rows' => User::with(['roles:id,name', 'lokasi:id,nama_lokasi'])->orderBy('name')->get()->map(fn (User $u) => $u->toBaris()),
            'roleOptions' => Role::orderBy('name')->pluck('name')->map(fn ($r) => ['value' => $r, 'label' => $r]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = User::create($data);
        $user->syncRoles($data['role']);

        return response()->json(['message' => "User {$user->name} ditambahkan"]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->pastikanBolehKelola($request, $user);
        $data = $this->validated($request, $user);
        $gantiPassword = filled($data['password'] ?? null);
        if (! $gantiPassword) {
            unset($data['password']);
        }
        $user->update($data);
        $user->syncRoles($data['role']);
        if ($gantiPassword || $user->status !== 'aktif') {
            $this->putusSesi($user);
        }

        return response()->json(['message' => "Data {$user->name} diperbarui"]);
    }

    /** Tombol Aktifkan / Nonaktifkan; user nonaktif langsung keluar dari semua sesinya. */
    public function status(Request $request, User $user): JsonResponse
    {
        $this->pastikanBolehKelola($request, $user);
        abort_if($user->is($request->user()), 422, 'Tidak bisa menonaktifkan akun sendiri');
        $user->update(['status' => $user->status === 'aktif' ? 'nonaktif' : 'aktif']);
        if ($user->status !== 'aktif') {
            $this->putusSesi($user);
        }

        return response()->json(['message' => "User {$user->name} ".($user->status === 'aktif' ? 'diaktifkan' : 'dinonaktifkan')]);
    }

    /** Akun Super Admin hanya boleh diubah oleh Super Admin. */
    private function pastikanBolehKelola(Request $request, User $user): void
    {
        abort_if($user->isSuperAdmin() && ! $request->user()->isSuperAdmin(), 403, 'Anda tidak memiliki akses ke fitur ini');
    }

    /** Hapus sesi login & token "ingat saya" user, sehingga harus login ulang. */
    private function putusSesi(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users')->ignore($user)],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('users')->ignore($user)],
            'tlp' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            // Kosong = semua cabang
            'id_lokasi' => ['nullable', 'integer', 'exists:lokasi,id'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
            'status' => ['required', 'in:aktif,nonaktif'],
        ], [
            ...$this->pesanValidasi(),
            'password.min' => 'Password minimal 8 karakter',
            'password.regex' => 'Password harus berisi huruf dan angka',
        ], [
            'nama' => 'Nama', 'username' => 'Username', 'email' => 'Email', 'tlp' => 'Tlp', 'role' => 'Role',
            'id_lokasi' => 'Akses cabang', 'password' => 'Password', 'status' => 'Status',
        ]);

        // Dropdown mengirim string; kosong = semua cabang
        $data['id_lokasi'] = filled($data['id_lokasi'] ?? null) ? (int) $data['id_lokasi'] : null;
        $saya = $request->user();
        if ($data['role'] === HakAkses::SUPER_ADMIN && ! $saya->isSuperAdmin()) {
            throw ValidationException::withMessages(['role' => 'Hanya Super Admin yang bisa memberi role Super Admin']);
        }
        if ($user?->is($saya)) {
            // Cegah mengunci diri sendiri / mengubah hak akses sendiri
            if ($data['role'] !== $user->roles->first()?->name) {
                throw ValidationException::withMessages(['role' => 'Tidak bisa mengganti role akun sendiri']);
            }
            if ($data['status'] !== 'aktif') {
                throw ValidationException::withMessages(['status' => 'Tidak bisa menonaktifkan akun sendiri']);
            }
            if ($data['id_lokasi'] !== $user->id_lokasi) {
                throw ValidationException::withMessages(['id_lokasi' => 'Tidak bisa mengubah akses cabang akun sendiri']);
            }
        }

        $data['name'] = $data['nama'];
        unset($data['nama']);

        return $data;
    }
}
