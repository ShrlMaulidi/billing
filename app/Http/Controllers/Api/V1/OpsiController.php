<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Support\OpsiForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pilihan dropdown form pelanggan & pembayaran sesuai cabang user (sama dengan halaman Pelanggan web). */
class OpsiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([...OpsiForm::untuk($request->user()), 'tahun_bayar' => Pembayaran::tahunBayar()]);
    }
}
