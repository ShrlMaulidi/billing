<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Profil memakai tabel users. Laporan, payment gateway, dan logs belum punya tabel,
 * sehingga tampil kosong sampai terhubung ke database.
 */
class SistemController extends Controller
{
    public function laporan(Request $request)
    {
        return Inertia::render('Laporan/Index', [
            'filters' => $this->filters($request) + ['mode' => $request->input('mode', 'bulanan')],
            'rows' => [],
            // Tahun yang tercakup 12 bulan terakhir
            'tahunList' => collect(range(11, 0))->map(fn ($i) => today()->startOfMonth()->subMonths($i)->year)->unique()->values(),
        ]);
    }

    public function paymentGateway(Request $request)
    {
        return Inertia::render('PaymentGateway/Index', [
            'filters' => $this->filters($request),
            'rows' => [],
        ]);
    }

    /** Channel tersedia setelah tabel payment gateway ada. */
    public function pgChannel(int $id)
    {
        abort(404);
    }

    public function logs(Request $request)
    {
        return Inertia::render('Logs/Index', [
            'filters' => $request->only(['dari', 'sampai', 'user', 'modul', 'aksi']),
            'rows' => [],
            'options' => ['user' => [], 'modul' => []],
        ]);
    }

    public function profil(Request $request)
    {
        return Inertia::render('User/Profil', ['user' => $request->user()->load(['roles:id,name', 'lokasi:id,nama_lokasi'])->toBaris()]);
    }
}
