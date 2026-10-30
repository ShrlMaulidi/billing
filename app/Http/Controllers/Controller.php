<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

abstract class Controller
{
    /** Filter bulan/tahun/cabang standar; default bulan berjalan. User cabang selalu terkunci ke cabangnya. */
    protected function filters(Request $request): array
    {
        $today = today();

        return [
            'bulan' => (int) $request->input('bulan', $today->month),
            'tahun' => (int) $request->input('tahun', $today->year),
            'cabang' => $request->user()?->id_lokasi ?? ($request->filled('cabang') ? (int) $request->input('cabang') : null),
        ];
    }

    /** Tolak akses ke data cabang lain dengan 404 (bukan 403) agar keberadaan datanya tidak terbaca. */
    protected function pastikanCabang(Request $request, ?int $idLokasi): void
    {
        abort_unless($request->user()->bolehAksesCabang($idLokasi), 404);
    }

    /**
     * Aturan tambahan untuk input id_lokasi: user cabang hanya boleh mengisi cabangnya sendiri.
     *
     * @return list<In|string>
     */
    protected function aturanCabangSendiri(Request $request): array
    {
        $cabang = $request->user()->id_lokasi;

        return $cabang ? ['required', Rule::in([$cabang])] : [];
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
