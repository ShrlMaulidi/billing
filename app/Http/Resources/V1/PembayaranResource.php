<?php

namespace App\Http\Resources\V1;

use App\Models\Pembayaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pembayaran / kwitansi. Relasi pelanggan, lokasi, rekening, dan petugas ikut tampil bila sudah dimuat.
 *
 * @mixin Pembayaran
 */
class PembayaranResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->only([
                'id', 'no_invoice', 'pelanggan_id', 'id_lokasi', 'periode', 'jns_tagihan', 'nama_pelanggan', 'nama_paket', 'harga', 'is_prorate',
                'diskon_tipe', 'diskon_nilai', 'diskon', 'jml_bayar', 'metode', 'rekening_id',
            ]),
            'periode_pembayaran' => $this->periodePembayaran(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'id_pelanggan' => $this->whenLoaded('pelanggan', fn () => $this->pelanggan?->id_pelanggan),
            'cabang' => $this->whenLoaded('lokasi', fn () => $this->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi'])),
            'rekening' => $this->whenLoaded('rekening', fn () => $this->rekening?->label()),
            'petugas' => $this->whenLoaded('petugas', fn () => $this->petugas?->name),
        ];
    }
}
