<?php

namespace App\Http\Resources\V1;

use App\Models\Pelanggan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pelanggan untuk aplikasi staf. tagihan = tagihan yang muncul di bulan tagihan filter (bulan & tahun request,
 * default bulan berjalan), dengan aturan yang sama seperti halaman Pelanggan web.
 * Muat relasi paket, lokasi, area, router, dan pembayaran (id, pelanggan_id, periode) lebih dulu.
 *
 * @mixin Pelanggan
 */
class PelangganResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bulanTagihan = sprintf('%04d-%02d-01', $request->integer('tahun', today()->year), $request->integer('bulan', today()->month));
        $periode = $this->periodePemakaian($bulanTagihan);
        $dibayar = $this->pembayaran->firstWhere('periode', $periode);

        return [
            ...$this->only([
                'id', 'id_pelanggan', 'nama', 'tlp', 'nik', 'alamat', 'jns_tagihan', 'skema_tagihan', 'jns_koneksi',
                'username_pppoe', 'ip_address', 'mac_address', 'tgl_jatuh_tempo', 'ppn', 'status', 'status_langganan',
                'id_lokasi', 'id_area', 'id_paket', 'router_id', 'sales_id',
            ]),
            'tgl_registrasi' => $this->tgl_registrasi?->toDateString(),
            'isolir_at' => $this->isolir_at?->toIso8601String(),
            'cabang' => $this->lokasi?->nama_lokasi,
            'area' => $this->area?->nama_area,
            'router' => $this->router?->nama_router,
            'paket' => $this->paket ? [
                'nama' => $this->paket->nama_paket,
                'harga' => $this->paket->harga,
                'profile' => $this->paket->profile_mikrotik,
            ] : null,
            'pembayaran_terakhir' => $this->pembayaran->max('periode'),
            'tagihan' => [
                'bulan_tagihan' => $bulanTagihan,
                'periode_pemakaian' => $periode,
                'nominal' => $this->tagihanUntuk($periode),
                'prorate' => $periode === $this->periodeProrate(),
                'lunas' => $dibayar !== null,
                'pembayaran_id' => $dibayar?->id,
            ],
        ];
    }
}
