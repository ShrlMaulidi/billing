<?php

namespace App\Services;

use App\Models\Lokasi;
use App\Models\Pelanggan;
use App\Models\User;
use App\Support\NomorHp;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Akun login pelanggan (role Pelanggan) yang dibuat otomatis dari Data Pelanggan.
 * Username & password awal = nomor HP terdaftar dalam format 08…; beberapa pelanggan bernomor HP sama memakai satu akun.
 * Akun yang tidak lagi dipakai pelanggan mana pun (nomor HP diganti / pelanggan dihapus) dinonaktifkan, dan bila nomor itu
 * dipakai lagi oleh pelanggan baru, password-nya dikembalikan ke nomor HP agar pemilik lama tidak bisa masuk.
 * Selama masih memakai password awal (wajib_ganti_password), pelanggan wajib membuat password baru setelah login.
 */
class AkunPelanggan
{
    public const ROLE = 'Pelanggan';

    public const KETERANGAN_ROLE = 'Akun pelanggan (dibuat otomatis dari Data Pelanggan)';

    /** Username yang sudah dipakai akun selain akun pelanggan (mis. user staf), sehingga tidak boleh dipakai sebagai akun pelanggan. */
    public static function dipakaiUserLain(string $tlp): bool
    {
        return User::where('username', NomorHp::normalkan($tlp))->withoutRole(self::ROLE)->exists();
    }

    /** Hubungkan pelanggan ke akun nomor HP-nya (dibuat bila belum ada), lalu lepaskan akun lama bila nomor HP berganti. */
    public function sinkronkan(Pelanggan $pelanggan): User
    {
        $username = NomorHp::normalkan((string) $pelanggan->tlp);
        $cabang = self::cabangAda($pelanggan->id_lokasi);
        $user = User::firstWhere('username', $username);
        if ($user && ! $user->isPelanggan()) {
            throw ValidationException::withMessages(['tlp' => 'No. telepon ini sudah dipakai sebagai username user aplikasi']);
        }

        if (! $user) {
            $user = User::create([
                'name' => $pelanggan->nama, 'username' => $username, 'tlp' => $pelanggan->tlp,
                'id_lokasi' => $cabang, 'password' => $username, 'wajib_ganti_password' => true,
            ]);
            $user->assignRole(Role::findOrCreate(self::ROLE, 'web'));
        } elseif ($user->pelanggan()->whereKeyNot($pelanggan->id)->doesntExist() && $pelanggan->user_id !== $user->id) {
            // Akun nomor ini sedang tidak dipakai siapa pun: anggap pemilik baru, kembalikan ke password awal
            $user->update(['name' => $pelanggan->nama, 'tlp' => $pelanggan->tlp, 'id_lokasi' => $cabang, 'status' => 'aktif', 'password' => $username, 'wajib_ganti_password' => true]);
            $user->putusSesi();
        } elseif ($user->pelanggan()->whereKeyNot($pelanggan->id)->doesntExist()) {
            // Akun milik pelanggan ini saja: ikuti nama & cabangnya
            $user->update(['name' => $pelanggan->nama, 'tlp' => $pelanggan->tlp, 'id_lokasi' => $cabang]);
        }

        $lama = $pelanggan->user_id;
        if ($lama !== $user->id) {
            $pelanggan->forceFill(['user_id' => $user->id])->saveQuietly();
            if ($lama) {
                $this->lepaskan(User::find($lama));
            }
        }

        return $user;
    }

    /** Akun yang sudah tidak dipakai pelanggan mana pun dinonaktifkan dan dikeluarkan dari sesinya. */
    public function lepaskan(?User $user): void
    {
        if (! $user?->isPelanggan() || $user->pelanggan()->exists() || $user->status !== 'aktif') {
            return;
        }
        // Lepas juga dari cabangnya agar tidak menghalangi penghapusan cabang
        $user->update(['status' => 'nonaktif', 'id_lokasi' => null]);
        $user->putusSesi();
    }

    /** Cabang pelanggan bila masih ada; data lama bisa menunjuk cabang yang sudah dihapus (akun dibuat tanpa cabang). */
    public static function cabangAda(?int $idLokasi): ?int
    {
        return $idLokasi !== null && Lokasi::whereKey($idLokasi)->exists() ? $idLokasi : null;
    }

    /** Kembalikan password ke nomor HP (username), wajib dibuat ulang saat login berikutnya, dan keluarkan dari semua sesi. */
    public function resetPassword(User $user): void
    {
        $user->update(['password' => $user->username, 'wajib_ganti_password' => true]);
        $user->putusSesi();
    }
}
