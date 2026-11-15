<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Catat tambah / ubah / hapus model ke jejak audit secara otomatis. Model pemakai mendefinisikan
 * `const MODUL_AUDIT` (nama modul di menu Logs) dan boleh menimpa labelAudit() serta KOLOM_TANPA_AUDIT.
 */
trait TercatatAudit
{
    /** Kolom yang perubahannya tidak perlu dicatat. */
    protected static array $kolomAbaikanAudit = ['created_at', 'updated_at', 'remember_token', 'last_login_at', 'last_login_ip', 'synced_at', 'sync_error'];

    public static function bootTercatatAudit(): void
    {
        static::created(fn (Model $m) => $m->catatAudit('create'));
        static::updated(fn (Model $m) => $m->catatAudit('update'));
        static::deleted(fn (Model $m) => $m->catatAudit('delete'));
    }

    /** Nama yang mudah dikenali untuk deskripsi log. */
    public function labelAudit(): string
    {
        foreach (['nama', 'nama_lokasi', 'nama_paket', 'nama_router', 'nama_tunnel', 'nama_area', 'nama_request', 'name', 'no_invoice', 'nama_rekening'] as $kolom) {
            if (filled($this->getAttribute($kolom))) {
                return (string) $this->getAttribute($kolom);
            }
        }

        return '#'.$this->getKey();
    }

    protected function catatAudit(string $aksi): void
    {
        if (Audit::sedangDiam()) {
            return;
        }
        $abaikan = [...self::$kolomAbaikanAudit, ...(defined('static::KOLOM_TANPA_AUDIT') ? static::KOLOM_TANPA_AUDIT : [])];
        $perubahan = [];
        $kolom = match ($aksi) {
            'update' => array_keys($this->getChanges()),
            'delete' => array_keys($this->getOriginal()),
            default => array_keys($this->getAttributes()),
        };
        foreach (array_diff($kolom, $abaikan, [$this->getKeyName()]) as $k) {
            $dari = Audit::nilai($this, $k, $this->getOriginal($k));
            $ke = Audit::nilai($this, $k, $this->getAttribute($k));
            $perubahan[$k] = match ($aksi) {
                // Di event "updated" nilai original masih nilai sebelum diubah (disinkronkan setelah event "saved")
                'update' => ['dari' => $dari, 'ke' => $ke],
                'delete' => ['dari' => $dari],
                default => ['ke' => $ke],
            };
        }
        // Ubah yang hanya menyentuh kolom yang diabaikan (mis. waktu login terakhir) tidak dicatat
        if ($aksi === 'update' && $perubahan === []) {
            return;
        }
        if ($aksi === 'create') {
            $perubahan = array_filter($perubahan, fn (array $p) => $p['ke'] !== null && $p['ke'] !== '');
        }
        $kata = ['create' => 'Menambah', 'update' => 'Mengubah', 'delete' => 'Menghapus'][$aksi];
        Audit::catat(static::MODUL_AUDIT, $aksi, "{$kata} {$this->namaJenisAudit()} {$this->labelAudit()}", $this, $perubahan);
    }

    /** Nama jenis data di deskripsi, mis. "pelanggan", "paket". */
    protected function namaJenisAudit(): string
    {
        return defined('static::JENIS_AUDIT') ? static::JENIS_AUDIT : strtolower(static::MODUL_AUDIT);
    }
}
