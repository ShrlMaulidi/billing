<?php

namespace App\Http\Resources\V1;

use App\Models\SetoranMitra;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Setoran mitra ke perusahaan. Foto bukti setor diunduh lewat endpoint bukti (bila ada_bukti).
 * Muat relasi lokasi, rekening, dan petugas lebih dulu.
 *
 * @mixin SetoranMitra
 */
class SetoranMitraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->only(['id', 'id_lokasi', 'periode', 'nominal', 'metode', 'rekening_id', 'keterangan']),
            'created_at' => $this->created_at?->toIso8601String(),
            'ada_bukti' => (bool) $this->bukti_setor,
            'cabang' => $this->lokasi?->nama_lokasi,
            'nama_mitra' => $this->lokasi?->nama_mitra,
            'rekening' => $this->rekening?->label(),
            'petugas' => $this->petugas?->name,
        ];
    }
}
