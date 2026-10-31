<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
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

    /**
     * Payment gateway mengikuti Admin Full cabang: cabang non Admin Full memakai gateway perusahaan (tidak bisa menambah),
     * cabang Admin Full menambah gateway sendiri. User pusat mengelola gateway perusahaan & gateway cabang Admin Full.
     */
    public function paymentGateway(Request $request)
    {
        $lokasiUser = $request->user()->lokasi;

        return Inertia::render('PaymentGateway/Index', [
            'filters' => $this->filters($request),
            'rows' => [],
            'cabangUser' => $lokasiUser?->only(['id', 'nama_lokasi', 'admin_full']),
            // Pilihan pemilik gateway untuk user pusat: perusahaan atau cabang Admin Full
            'pemilikOptions' => $lokasiUser ? [] : Lokasi::where('admin_full', true)->orderBy('nama_lokasi')->get(['id', 'nama_lokasi'])
                ->map(fn (Lokasi $l) => ['value' => $l->id, 'label' => "{$l->nama_lokasi} (Admin Full)"])
                ->prepend(['value' => 'perusahaan', 'label' => 'Perusahaan (dipakai cabang non Admin Full)'])->values(),
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
