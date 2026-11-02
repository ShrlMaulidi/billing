<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lokasi;
use App\Support\RekapCabang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rekap keuangan per cabang (isi Dashboard Cabang web) untuk bulan & tahun filter. User cabang hanya cabangnya. */
class RekapCabangController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json([
            'filters' => $f,
            'data' => RekapCabang::hitung(Lokasi::when($f['cabang'], fn ($q, $id) => $q->whereKey($id))->orderBy('kode_lokasi')->get(), $this->periode($f)),
        ]);
    }
}
