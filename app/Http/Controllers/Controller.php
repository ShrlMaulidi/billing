<?php

namespace App\Http\Controllers;

use App\Support\MockData;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Filter bulan/tahun/cabang standar; default bulan berjalan. */
    protected function filters(Request $request): array
    {
        $today = MockData::today();

        return [
            'bulan' => (int) $request->input('bulan', $today->month),
            'tahun' => (int) $request->input('tahun', $today->year),
            'cabang' => $request->filled('cabang') ? (int) $request->input('cabang') : null,
        ];
    }

    protected function periode(array $f): string
    {
        return sprintf('%04d-%02d-01', $f['tahun'], $f['bulan']);
    }
}
