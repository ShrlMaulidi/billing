<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pencatat jejak audit (menu Logs). Pelaku diambil dari user login; tanpa login dari terminal tercatat "Sistem (terminal)".
 * Kegagalan mencatat tidak boleh menggagalkan aksi pengguna: dilaporkan ke log error lalu diabaikan.
 */
class Audit
{
    /** Kolom yang tidak pernah dicatat nilainya. */
    private const RAHASIA = ['password', 'remember_token', 'api_password', 'secret', 'token'];

    public const DISAMARKAN = '[disembunyikan]';

    /** Lebih dari 0 = pencatatan otomatis (model event) dimatikan sementara, lihat diam(). */
    private static int $diam = 0;

    /** Nama pelaku bila tidak ada user login, lihat sebagai(). */
    private static ?string $pelakuBawaan = null;

    /**
     * @param  array<string, array{dari?: mixed, ke?: mixed}>|null  $perubahan
     */
    public static function catat(string $modul, string $aksi, string $deskripsi, ?Model $subjek = null, ?array $perubahan = null, ?string $pelaku = null): void
    {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $user = $request?->user();
            AuditLog::create([
                'created_at' => now(),
                'user_id' => $user?->id,
                'user_nama' => Str::limit($user?->name ?? $pelaku ?? self::$pelakuBawaan ?? ($request ? 'Tamu' : 'Sistem (terminal)'), 97),
                'user_role' => $user?->getRoleNames()->first(),
                'id_lokasi' => $user?->id_lokasi,
                'modul' => $modul,
                'aksi' => $aksi,
                'deskripsi' => Str::limit($deskripsi, 497),
                'subjek_type' => $subjek ? class_basename($subjek) : null,
                'subjek_id' => $subjek?->getKey(),
                'perubahan' => $perubahan ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 252) : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Jalankan aksi tanpa pencatatan otomatis model, untuk aksi yang dicatat eksplisit dengan deskripsi sendiri
     * (mis. isolir mengubah kolom pelanggan, cukup tercatat satu kali sebagai "isolir").
     *
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     */
    public static function diam(callable $aksi): mixed
    {
        self::$diam++;
        try {
            return $aksi();
        } finally {
            self::$diam--;
        }
    }

    /**
     * Jalankan aksi dengan nama pelaku tertentu untuk catatan tanpa user login, mis. webhook Midtrans.
     *
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     */
    public static function sebagai(string $pelaku, callable $aksi): mixed
    {
        $sebelum = self::$pelakuBawaan;
        self::$pelakuBawaan = $pelaku;
        try {
            return $aksi();
        } finally {
            self::$pelakuBawaan = $sebelum;
        }
    }

    public static function sedangDiam(): bool
    {
        return self::$diam > 0;
    }

    /** Nilai kolom aman untuk dicatat: kolom rahasia & tersembunyi model disamarkan, teks panjang dipotong. */
    public static function nilai(Model $model, string $kolom, mixed $nilai): mixed
    {
        if (in_array($kolom, self::RAHASIA, true) || in_array($kolom, $model->getHidden(), true)) {
            return $nilai === null || $nilai === '' ? null : self::DISAMARKAN;
        }
        if ($nilai instanceof \DateTimeInterface) {
            return $nilai->format('Y-m-d H:i:s');
        }
        if (is_string($nilai) && mb_strlen($nilai) > 300) {
            return mb_substr($nilai, 0, 300).'…';
        }

        return $nilai;
    }
}
