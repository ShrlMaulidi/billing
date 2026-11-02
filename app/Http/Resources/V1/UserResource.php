<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Support\HakAkses;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun staf yang login di aplikasi mobile. permissions dipakai aplikasi untuk menyembunyikan menu/tombol;
 * server tetap memeriksa permission di setiap endpoint.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $superAdmin = $this->isSuperAdmin();

        return [
            'id' => $this->id,
            'nama' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'tlp' => $this->tlp,
            'role' => $this->roles->first()?->name,
            'super_admin' => $superAdmin,
            // null = semua cabang
            'id_lokasi' => $this->id_lokasi,
            'cabang' => $this->lokasi?->nama_lokasi ?? 'Semua Cabang',
            // Super Admin lolos semua pemeriksaan (Gate::before), jadi diberi daftar lengkap
            'permissions' => $superAdmin ? HakAkses::permissions() : $this->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
