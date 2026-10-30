<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\PajakCabang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/** Pengaturan Pajak: tarif pajak & bagi hasil per cabang per bulan (Januari–Desember). */
class PajakController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);
        // Tahun yang bisa diatur tarifnya: tahun lalu s/d tahun depan
        $tahun = today()->year;
        $tahunOptions = [$tahun - 1, $tahun, $tahun + 1];
        if (! in_array($f['tahun'], $tahunOptions)) {
            $f['tahun'] = $tahun;
        }
        // Tarif selalu per satu cabang; tanpa pilihan, pakai cabang pertama di dropdown
        $f['cabang'] = Lokasi::when($f['cabang'], fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->value('id');

        return Inertia::render('KelolaData/Pajak', [
            'filters' => $f,
            'rows' => $f['cabang'] ? PajakCabang::setahun($f['cabang'], $f['tahun']) : [],
            'tahunOptions' => $tahunOptions,
            'periodeSekarang' => today()->format('Y-m-01'),
        ]);
    }

    /** Mengubah tarif satu bulan saja; bulan & tahun lain tidak ikut berubah. */
    public function update(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);
        $data = $request->validate([
            'tahun' => ['required', 'integer', 'between:2000,2100'],
            'bulan' => ['required', 'integer', 'between:1,12'],
            ...PajakCabang::aturan($request->all()),
        ], $this->pesanValidasi(), ['tahun' => 'Tahun', 'bulan' => 'Bulan', ...PajakCabang::ATRIBUT]);
        $periode = Carbon::create($data['tahun'], $data['bulan']);

        $lokasi->pajak()->updateOrCreate(
            ['periode' => $periode->format('Y-m-d')],
            collect($data)->only(PajakCabang::TARIF)->all(),
        );

        return response()->json(['message' => "Tarif {$lokasi->nama_lokasi} {$periode->locale('id')->translatedFormat('F Y')} disimpan"]);
    }
}
