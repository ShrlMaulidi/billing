<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Satu catatan jejak audit (menu Logs). Dibuat lewat App\Support\Audit. Catatan bersifat tetap: tidak bisa diubah atau
 * dihapus lewat model; hanya dipangkas massal setelah masa simpan oleh `php artisan model:prune` (terjadwal harian).
 */
#[Table('audit_log')]
#[Fillable(['created_at', 'user_id', 'user_nama', 'user_role', 'id_lokasi', 'modul', 'aksi', 'deskripsi', 'subjek_type', 'subjek_id', 'perubahan', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    use MassPrunable;

    /** Masa simpan catatan audit. */
    public const MASA_SIMPAN_HARI = 365;

    /** Jenis aksi yang dicatat (dipakai filter halaman Logs). */
    public const AKSI = ['create', 'update', 'delete', 'login', 'login_gagal', 'logout', 'isolir', 'aktifkan', 'sinkron', 'approve', 'reject', 'export', 'lihat'];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Catatan audit tidak boleh diubah'));
        static::deleting(fn () => throw new LogicException('Catatan audit tidak boleh dihapus'));
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'perubahan' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::MASA_SIMPAN_HARI));
    }
}
