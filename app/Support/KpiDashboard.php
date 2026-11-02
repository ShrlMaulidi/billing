<?php

namespace App\Support;

use App\Models\Lokasi;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\User;

/** Kartu KPI Dashboard & saldo mitra; dipakai halaman Dashboard web dan API mobile agar angkanya selalu sama. */
class KpiDashboard
{
    /**
     * Jumlah pelanggan per status (bulan berjalan) dan pemasukan bulan ini & hari ini menurut tanggal bayar.
     *
     * @return array{total: int, lunas: int, belum_lunas: int, on: int, off: int, berhenti: int, pemasukan_bulan: int, pemasukan_hari: int}
     */
    public static function hitung(?int $idLokasi): array
    {
        $today = today();
        $bulanIni = $today->format('Y-m-01');
        $pelanggan = Pelanggan::query()->when($idLokasi, fn ($q) => $q->where('id_lokasi', $idLokasi));
        $aktif = (clone $pelanggan)->where('status', 'aktif');
        $pembayaran = Pembayaran::query()->when($idLokasi, fn ($q) => $q->where('id_lokasi', $idLokasi));

        return [
            'total' => (clone $aktif)->count(),
            'lunas' => (clone $pelanggan)->lunas($bulanIni)->count(),
            'belum_lunas' => (clone $pelanggan)->belumLunas($bulanIni)->count(),
            'on' => (clone $aktif)->where('status_langganan', 'on')->count(),
            'off' => (clone $aktif)->where('status_langganan', 'off')->count(),
            'berhenti' => (clone $pelanggan)->where('status', 'berhenti')->count(),
            'pemasukan_bulan' => (int) (clone $pembayaran)->whereBetween('paid_at', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])->sum('jml_bayar'),
            'pemasukan_hari' => (int) (clone $pembayaran)->where('paid_at', '>=', $today->copy()->startOfDay())->sum('jml_bayar'),
        ];
    }

    /**
     * Saldo cabang milik user ber-role Mitra (selalu cabangnya sendiri); null untuk role lain.
     * Memakai baris rekap cabang yang sama dengan Dashboard Cabang.
     *
     * @param  string  $periode  Y-m-01
     * @return array<string, mixed>|null baris rekap cabang + nama_mitra
     */
    public static function saldoMitra(User $user, string $periode): ?array
    {
        $lokasi = $user->id_lokasi && $user->hasRole('Mitra') ? Lokasi::find($user->id_lokasi) : null;
        if (! $lokasi) {
            return null;
        }

        return [...RekapCabang::hitung($lokasi->newCollection([$lokasi]), $periode)->first(), 'nama_mitra' => $lokasi->nama_mitra];
    }
}
