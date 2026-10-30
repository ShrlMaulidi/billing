<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Filter bulan/tahun/cabang standar; default bulan berjalan. */
    protected function filters(Request $request): array
    {
        $today = today();

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
            'required_unless' => ':attribute wajib diisi',
            'digits' => ':attribute harus :digits digit angka',
            'integer' => ':attribute harus berupa angka',
            'between' => ':attribute harus antara :min dan :max',
            'exists' => ':attribute tidak ditemukan',
            'in' => ':attribute tidak valid',
            'max' => ':attribute maksimal :max karakter',
            'min' => ':attribute minimal :min',
            'boolean' => ':attribute tidak valid',
            'regex' => 'Format :attribute tidak valid',
            'not_regex' => ':attribute mengandung karakter yang tidak diizinkan',
            'ip' => ':attribute harus berupa alamat IP yang valid',
            'unique' => ':attribute sudah dipakai',
            'date' => ':attribute tidak valid',
        ];
    }
}
