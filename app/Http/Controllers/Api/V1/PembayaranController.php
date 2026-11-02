<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PembayaranResource;
use App\Models\Pembayaran;
use Illuminate\Http\Request;

/** Kwitansi satu pembayaran. Input, riwayat, edit, dan pembatalan memakai PembayaranController web. */
class PembayaranController extends Controller
{
    public function show(Request $request, Pembayaran $pembayaran): PembayaranResource
    {
        $this->pastikanCabang($request, $pembayaran->id_lokasi);

        return new PembayaranResource($pembayaran->load(['pelanggan:id,id_pelanggan', 'lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi', 'rekening', 'petugas:id,name']));
    }
}
