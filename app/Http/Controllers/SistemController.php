<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Throwable;

/**
 * Profil (tabel users) dan Logs (jejak audit). Payment gateway ada di PaymentGatewayController.
 */
class SistemController extends Controller
{
    /** Batas catatan audit per tampilan; persempit rentang tanggal atau filter untuk melihat sisanya. */
    private const MAKS_LOG = 2000;

    /**
     * Jejak audit (menu Logs), terbaru dulu. Default 7 hari terakhir. User cabang hanya melihat aktivitas user cabangnya
     * sendiri; user pusat melihat semua.
     */
    public function logs(Request $request)
    {
        $tanggal = function (?string $nilai, Carbon $bawaan): Carbon {
            try {
                return $nilai ? Carbon::createFromFormat('!Y-m-d', $nilai) : $bawaan;
            } catch (Throwable) {
                return $bawaan;
            }
        };
        $sampai = $tanggal($request->input('sampai'), today());
        $dari = $tanggal($request->input('dari'), $sampai->copy()->subDays(6))->min($sampai);
        $f = [
            'dari' => $dari->toDateString(),
            'sampai' => $sampai->toDateString(),
            'user' => $request->input('user'),
            'modul' => $request->input('modul'),
            'aksi' => in_array($request->input('aksi'), AuditLog::AKSI, true) ? $request->input('aksi') : null,
        ];
        $cabang = fn ($q) => $q->when($request->user()->id_lokasi, fn ($q, $id) => $q->where('id_lokasi', $id));
        $query = AuditLog::query()->tap($cabang)
            ->whereBetween('created_at', [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()])
            ->when($f['user'], fn ($q, $u) => $q->where('user_nama', $u))
            ->when($f['modul'], fn ($q, $m) => $q->where('modul', $m))
            ->when($f['aksi'], fn ($q, $a) => $q->where('aksi', $a));

        return Inertia::render('Logs/Index', [
            'filters' => $f,
            'total' => (clone $query)->count(),
            'batas' => self::MAKS_LOG,
            'rows' => $query->latest('id')->limit(self::MAKS_LOG)->get()->map(fn (AuditLog $l) => [
                'id' => $l->id, 'created_at' => $l->created_at, 'user' => $l->user_nama, 'role' => $l->user_role, 'modul' => $l->modul,
                'aksi' => $l->aksi, 'deskripsi' => $l->deskripsi, 'perubahan' => $l->perubahan, 'ip_address' => $l->ip_address, 'user_agent' => $l->user_agent,
            ]),
            'options' => [
                'user' => AuditLog::query()->tap($cabang)->whereNotNull('user_nama')->distinct()->orderBy('user_nama')->pluck('user_nama'),
                'modul' => AuditLog::query()->tap($cabang)->distinct()->orderBy('modul')->pluck('modul'),
                'aksi' => AuditLog::AKSI,
            ],
        ]);
    }

    public function profil(Request $request)
    {
        return Inertia::render('User/Profil', ['user' => $request->user()->load(['roles:id,name', 'lokasi:id,nama_lokasi'])->toBaris()]);
    }
}
