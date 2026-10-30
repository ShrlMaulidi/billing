<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\HakAkses;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Akun login. Role & permission lewat spatie/laravel-permission (lihat App\Support\HakAkses).
 * id_lokasi terisi = user hanya boleh mengakses data cabang itu; kosong = semua cabang.
 */
#[Fillable(['name', 'username', 'email', 'tlp', 'id_lokasi', 'status', 'password', 'last_login_at', 'last_login_ip'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Sama dengan default kolom, agar user yang baru dibuat langsung terbaca aktif. */
    protected $attributes = ['status' => 'aktif'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'id_lokasi' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(HakAkses::SUPER_ADMIN);
    }

    /** Akun aktif yang punya setidaknya satu menu untuk dibuka. */
    public function bolehMasuk(): bool
    {
        return $this->status === 'aktif' && HakAkses::halamanAwal($this) !== null;
    }

    /**
     * Data akun untuk halaman Manajemen User & Profil. Muat relasi roles & lokasi lebih dulu agar tidak N+1.
     *
     * @return array<string, mixed>
     */
    public function toBaris(): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'tlp' => $this->tlp,
            'role' => $this->roles->first()?->name,
            'id_lokasi' => $this->id_lokasi,
            'cabang_akses' => $this->lokasi?->nama_lokasi ?? 'Semua Cabang',
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toDateTimeString(),
            'last_login_ip' => $this->last_login_ip,
        ];
    }

    /** Data cabang $idLokasi boleh diakses? Data tanpa cabang (null) hanya untuk user semua-cabang. */
    public function bolehAksesCabang(?int $idLokasi): bool
    {
        return $this->id_lokasi === null || $this->id_lokasi === $idLokasi;
    }
}
