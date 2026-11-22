<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Penanda perubahan payment gateway untuk Super Admin di Dashboard. Gateway menentukan ke rekening mana uang pelanggan masuk,
 * jadi setiap gateway ditambah, dihapus, diganti kuncinya, dijadikan Utama, atau diubah status/modenya ditampilkan mencolok
 * sampai Super Admin menandainya "sudah diperiksa". Sumber data: log audit modul Payment Gateway (kunci tetap disamarkan).
 */
class PerubahanGateway
{
    public const MODUL = 'Payment Gateway';

    /** Rentang riwayat yang ditampilkan bila belum pernah diperiksa. */
    private const HARI = 30;

    private const MAKS = 10;

    /**
     * @return array{baru: int, daftar: list<array<string, mixed>>}|null null bila bukan Super Admin
     */
    public static function untuk(User $user): ?array
    {
        if (! $user->isSuperAdmin()) {
            return null;
        }
        $sejak = $user->gateway_diperiksa_at ?? now()->subDays(self::HARI);
        $daftar = AuditLog::where('modul', self::MODUL)->whereIn('aksi', ['create', 'update', 'delete'])->where('created_at', '>', $sejak)
            ->latest('created_at')->latest('id')->limit(50)->get()
            ->map(fn (AuditLog $log) => self::baris($log))->filter()->values();

        return ['baru' => $daftar->count(), 'daftar' => $daftar->take(self::MAKS)->all()];
    }

    /** @return array<string, mixed>|null null untuk catatan yang bukan perubahan penting (mis. Utama lama yang otomatis jadi cadangan) */
    private static function baris(AuditLog $log): ?array
    {
        $ubah = $log->perubahan ?? [];
        $poin = match ($log->aksi) {
            'create' => ['Gateway baru ditambahkan'],
            'delete' => ['Gateway dihapus'],
            default => array_values(array_filter([
                isset($ubah['kredensial']) ? 'Server Key / Signature Key diganti' : null,
                ($ubah['utama']['ke'] ?? null) === true ? 'Dijadikan Utama' : null,
                isset($ubah['status']) ? 'Status: '.self::status($ubah['status']['dari'] ?? null).' → '.self::status($ubah['status']['ke'] ?? null) : null,
                isset($ubah['produksi']) ? 'Mode: '.self::mode($ubah['produksi']['dari'] ?? null).' → '.self::mode($ubah['produksi']['ke'] ?? null) : null,
                isset($ubah['nama']) ? 'Nama: '.($ubah['nama']['dari'] ?? '-').' → '.($ubah['nama']['ke'] ?? '-') : null,
            ])),
        };
        if (! $poin) {
            return null;
        }

        return [
            'id' => $log->id,
            'waktu' => $log->created_at?->toDateTimeString(),
            'pelaku' => $log->user_nama,
            'role' => $log->user_role,
            'ip' => $log->ip_address,
            'deskripsi' => $log->deskripsi,
            'poin' => $poin,
            // Penting: memindahkan arah uang (kunci baru, gateway baru, atau Utama baru)
            'penting' => $log->aksi === 'create' || isset($ubah['kredensial']) || ($ubah['utama']['ke'] ?? null) === true,
        ];
    }

    private static function status(mixed $nilai): string
    {
        return $nilai === 'aktif' ? 'Aktif' : 'Tidak Aktif';
    }

    private static function mode(mixed $nilai): string
    {
        return $nilai ? 'Production' : 'Sandbox';
    }
}
