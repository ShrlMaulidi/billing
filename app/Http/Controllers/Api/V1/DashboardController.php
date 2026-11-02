<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\KpiDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kartu KPI Dashboard (+ saldo untuk role Mitra), angka sama dengan Dashboard web. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json([
            'filters' => $f,
            'kpi' => KpiDashboard::hitung($f['cabang']),
            'saldo_mitra' => KpiDashboard::saldoMitra($request->user(), $this->periode($f)),
        ]);
    }
}
