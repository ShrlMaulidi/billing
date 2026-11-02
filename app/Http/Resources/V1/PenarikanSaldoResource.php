<?php

namespace App\Http\Resources\V1;

use App\Models\PenarikanSaldo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengajuan penarikan saldo mitra. Foto bukti transfer diunduh lewat endpoint bukti (bila ada_bukti).
 * Muat relasi lokasi, pemohon, dan pemroses lebih dulu.
 *
 * @mixin PenarikanSaldo
 */
class PenarikanSaldoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->only([
                'id', 'id_lokasi', 'nama_request', 'periode_sumber', 'nominal', 'keterangan', 'bank_penerima', 'no_rekening_penerima',
                'atas_nama_penerima', 'status', 'catatan',
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'diproses_at' => $this->diproses_at?->toIso8601String(),
            'ada_bukti' => (bool) $this->bukti_transfer,
            'cabang' => $this->lokasi?->nama_lokasi,
            'nama_mitra' => $this->lokasi?->nama_mitra,
            'pemohon' => $this->pemohon?->name,
            'diproses_oleh' => $this->pemroses?->name,
        ];
    }
}
