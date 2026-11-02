<?php

namespace App\Support;

use App\Models\Area;
use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Rekening;
use App\Models\Router;
use App\Models\User;

/**
 * Pilihan dropdown form pelanggan & pembayaran sesuai cabang user: milik cabangnya, plus yang berlaku untuk semua cabang.
 * id_lokasi dkk. di tiap pilihan dipakai form untuk menyaring sesuai cabang & jenis koneksi. Dipakai halaman Pelanggan dan API mobile.
 */
class OpsiForm
{
    /**
     * @return array{cabang: mixed, rekening: mixed, area: mixed, router: mixed, paket: mixed, sales: mixed}
     */
    public static function untuk(User $user): array
    {
        $cabangUser = $user->id_lokasi;
        $cabangDanUmum = fn ($q) => $q->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $cabangUser));

        return [
            'cabang' => Lokasi::when($cabangUser, fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get(['id', 'nama_lokasi', 'terima_cash'])
                ->map(fn (Lokasi $l) => ['value' => $l->id, 'label' => $l->nama_lokasi, 'terima_cash' => $l->terima_cash]),
            'rekening' => Rekening::when($cabangUser, $cabangDanUmum)->orderBy('nama_rekening')->get()
                ->map(fn (Rekening $r) => ['value' => (string) $r->id, 'label' => $r->label(), 'id_lokasi' => $r->id_lokasi]),
            'area' => Area::when($cabangUser, fn ($q) => $q->where('id_lokasi', $cabangUser))->orderBy('nama_area')->get()
                ->map(fn (Area $a) => ['value' => $a->id, 'label' => $a->nama_area, 'id_lokasi' => $a->id_lokasi]),
            'router' => Router::when($cabangUser, $cabangDanUmum)->orderBy('nama_router')->get()
                ->map(fn (Router $r) => ['value' => $r->id, 'label' => $r->nama_router, 'id_lokasi' => $r->id_lokasi]),
            'paket' => Paket::when($cabangUser, fn ($q) => $q->where(fn ($q) => $q->where('filter_lokasi', false)->orWhere('id_lokasi', $cabangUser)))
                ->orderBy('nama_paket')->get()->map(fn (Paket $p) => [
                    'value' => $p->id, 'label' => "{$p->nama_paket} ({$p->profile_mikrotik}) · Rp".number_format($p->harga, 0, ',', '.'),
                    'id_lokasi' => $p->filter_lokasi ? $p->id_lokasi : null, 'jns_koneksi' => $p->jns_koneksi,
                ]),
            'sales' => User::role('Sales')->where('status', 'aktif')->when($cabangUser, $cabangDanUmum)->orderBy('name')->get()
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name, 'id_lokasi' => $u->id_lokasi]),
        ];
    }
}
