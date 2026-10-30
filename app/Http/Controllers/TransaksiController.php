<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

/** Transaksi mitra & pengeluaran belum punya tabel: halaman tampil kosong sampai terhubung ke database. */
class TransaksiController extends Controller
{
    public function penarikan(Request $request)
    {
        return Inertia::render('Transaksi/Penarikan', [
            'filters' => $this->filters($request) + ['status' => $request->input('status'), 'semua' => $request->boolean('semua')],
            'rows' => [],
            'saldo' => (object) [],
        ]);
    }

    public function setor(Request $request)
    {
        return Inertia::render('Transaksi/Setor', [
            'filters' => $this->filters($request) + ['semua' => $request->boolean('semua')],
            'rows' => [],
            'kewajiban' => (object) [],
            'rekeningOptions' => [],
        ]);
    }

    public function pengeluaran(Request $request)
    {
        return Inertia::render('Transaksi/Pengeluaran', [
            'filters' => $this->filters($request),
            'rows' => [],
            'ringkasan' => ['pemasukan' => 0, 'pengeluaran' => 0, 'balance' => 0],
            'master' => [],
            'rekeningOptions' => [],
        ]);
    }
}
