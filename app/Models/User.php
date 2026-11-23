<?php

namespace App\Models;

use App\Services\AkunPelanggan;
use App\Support\HakAkses;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\TercatatAudit;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * Akun login. Role & permission lewat spatie/laravel-permission (lihat App\Support\HakAkses).
 * id_lokasi terisi = user hanya boleh mengakses data cabang itu; kosong = semua cabang.
 */
#[Fillable(['name', 'username', 'email', 'tlp', 'id_lokasi', 'status', 'password', 'wajib_ganti_password', 'last_login_at', 'last_login_ip'])]
#[Hidden(['password', 'remember_token', 'foto'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TercatatAudit;

    /** Nama modul & jenis data di menu Logs (jejak audit). */
    public const MODUL_AUDIT = 'User';

    public const JENIS_AUDIT = 'user';

    /** Folder foto profil di disk privat `local`; file hanya disajikan ke pemilik akunnya (PortalPelangganController::foto). */
    public const FOLDER_FOTO = 'foto-profil';

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
            'wajib_ganti_password' => 'boolean',
            'id_lokasi' => 'integer',
            'last_login_at' => 'datetime',
            'gateway_diperiksa_at' => 'datetime',
        ];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    /** Pelanggan yang memakai akun ini (role Pelanggan; satu nomor HP bisa dipakai beberapa pelanggan). */
    public function pelanggan(): HasMany
    {
        return $this->hasMany(Pelanggan::class);
    }

    /** Akun pelanggan: hanya membuka halaman Akun Saya, tidak tampil di Manajemen User. */
    public function isPelanggan(): bool
    {
        return $this->hasRole(AkunPelanggan::ROLE);
    }

    /**
     * URL foto profil untuk tampilan (avatar header & menu Akun), atau null bila belum ada. Parameter v berubah setiap foto
     * diganti agar browser tidak memakai foto lama dari cache.
     */
    public function urlFoto(): ?string
    {
        return $this->foto ? route('akun-saya.foto', ['v' => substr(md5($this->foto), 0, 10)], absolute: false) : null;
    }

    /** Hapus sesi login & token "ingat saya", sehingga user harus login ulang. */
    public function putusSesi(): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();
        }
        $this->forceFill(['remember_token' => Str::random(60)])->save();
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
