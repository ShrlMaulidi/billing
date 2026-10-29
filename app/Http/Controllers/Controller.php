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

    /** Pesan validasi berbahasa Indonesia (aplikasi belum memakai file terjemahan). */
    protected function pesanValidasi(): array
    {
        return [
            'required' => ':attribute wajib diisi',
            'required_if' => ':attribute wajib diisi',
            'integer' => ':attribute harus berupa angka',
            'between' => ':attribute harus antara :min dan :max',
            'exists' => ':attribute tidak ditemukan',
            'in' => ':attribute tidak valid',
            'max' => ':attribute maksimal :max karakter',
            'ip' => ':attribute harus berupa alamat IP yang valid',
            'unique' => ':attribute sudah dipakai',
            'date' => ':attribute tidak valid',
        ];
    }
}
