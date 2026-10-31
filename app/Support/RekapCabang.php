<?php

namespace App\Support;

use App\Models\Lokasi;
use App\Models\PajakCabang;
use App\Models\Pembayaran;
use App\Models\PenarikanSaldo;
use App\Models\SetoranMitra;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap keuangan per cabang untuk satu periode; dipakai Dashboard (Mitra & Cabang) dan Penarikan Saldo agar angka saldo selalu sama.
 * Pembayaran dihitung menurut tanggal bayar dengan skema withdrawal PajakCabang::rincian().
 * Pemasukan via mitra = cash + transfer (setiap cabang memakai rekeningnya sendiri) + payment gateway bila cabang Admin Full.
 * Hanya payment gateway cabang non Admin Full (gateway perusahaan) yang diterima MSS.
 * Saldo = pendapatan reseller − pemasukan via mitra + sudah setor − sudah WD; minus berarti mitra masih harus setor ke MSS.
 * Sudah setor = setoran mitra berperiode bulan itu; sudah WD = penarikan berperiode sumber bulan itu yang sudah disetujui.
 */
class RekapCabang
{
    /** Kolom rekap yang masih 0 sampai modul tagihan dan pengeluaran punya tabel. */
    private const NOMINAL_KOSONG = ['lunas', 'belum_lunas', 'pengeluaran'];

    /** Metode pembayaran lewat payment gateway (selain cash & transfer manual). */
    public const PAYMENT_GATEWAY = ['tripay', 'xendit', 'pakar_digital', 'moota'];

    /**
     * @param  EloquentCollection<int, Lokasi>  $lokasi
     * @param  string  $periode  Y-m-01
     * @return Collection<int, array<string, mixed>>
     */
    public static function hitung(EloquentCollection $lokasi, string $periode): Collection
    {
        $awal = Carbon::parse($periode);
        $lokasi->loadCount([
            'pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif'),
            'pelanggan as pelanggan_baru' => fn ($q) => $q->whereBetween('tgl_registrasi', [$periode, $awal->copy()->endOfMonth()->toDateString()]),
            'pelanggan as pelanggan_free' => fn ($q) => $q->where('status', 'aktif')->whereHas('paket', fn ($p) => $p->where('harga', 0)),
        ]);
        $tarif = PajakCabang::berlaku($lokasi->modelKeys(), $periode);
        $pembayaran = Pembayaran::whereIn('id_lokasi', $lokasi->modelKeys())
            ->whereBetween('paid_at', [$awal->copy()->startOfDay(), $awal->copy()->endOfMonth()])
            ->get(['id_lokasi', 'metode', 'jml_bayar'])
            ->groupBy('id_lokasi');
        $penarikan = PenarikanSaldo::whereIn('id_lokasi', $lokasi->modelKeys())->where('periode_sumber', $periode)
            ->whereIn('status', PenarikanSaldo::MENGURANGI_SALDO)
            ->groupBy('id_lokasi')->selectRaw('id_lokasi, SUM(nominal) as total')->pluck('total', 'id_lokasi');
        $setoran = SetoranMitra::whereIn('id_lokasi', $lokasi->modelKeys())->where('periode', $periode)
            ->groupBy('id_lokasi')->selectRaw('id_lokasi, SUM(nominal) as total')->pluck('total', 'id_lokasi');

        return $lokasi->map(function (Lokasi $l) use ($tarif, $pembayaran, $penarikan, $setoran) {
            $t = $tarif->get($l->id);
            $bayar = $pembayaran->get($l->id, collect());
            $pemasukan = (float) $bayar->sum('jml_bayar');
            $cash = (float) $bayar->where('metode', 'cash')->sum('jml_bayar');
            $pg = (float) $bayar->whereIn('metode', self::PAYMENT_GATEWAY)->sum('jml_bayar');
            $viaMitra = $pemasukan - ($l->admin_full ? 0 : $pg);
            $rincian = PajakCabang::rincian($t, $pemasukan, $bayar->count());
            $nominal = [...array_fill_keys(self::NOMINAL_KOSONG, 0), 'sudah_setor' => (int) $setoran->get($l->id, 0), 'total_wd' => (int) $penarikan->get($l->id, 0)];
            $saldo = $rincian['pendapatan_reseller'] - $viaMitra + $nominal['sudah_setor'] - $nominal['total_wd'];

            return [
                ...$nominal,
                ...array_map(fn (float $v) => (int) round($v), $rincian),
                'pemasukan' => (int) $pemasukan,
                'pemasukan_via_mitra' => (int) $viaMitra,
                'total_cash' => (int) $cash,
                'total_pg' => (int) $pg,
                'admin_full' => (bool) $l->admin_full,
                'sisa_setor' => (int) round($rincian['harus_setor'] - $nominal['sudah_setor']),
                'sisa_wd' => (int) round($rincian['pendapatan_reseller'] - $nominal['total_wd']),
                'saldo' => (int) round($saldo),
                'id_lokasi' => $l->id,
                'nama_cabang' => $l->nama_lokasi,
                'jumlah_pelanggan' => $l->jumlah_pelanggan,
                'pelanggan_baru' => $l->pelanggan_baru,
                'pelanggan_free' => $l->pelanggan_free,
                'ppn_persen' => $t?->ppn_persen,
                'pph_persen' => $t?->pph_persen,
                // Label "/trx" agar kartu ringkasan tidak menambahkan tanda %
                'admin_fee_label' => $t ? ($t->admin_fee_tipe === 'persentase' ? $t->admin_fee : 'Rp'.number_format($t->admin_fee, 0, ',', '.').'/trx') : null,
                'metode_bagi_hasil' => $t ? ($t->metode_bagi_hasil === 'persentase'
                    ? 'Persentase '.str_replace('.', ',', (string) $t->nilai_bagi_hasil).'%'
                    : 'Nominal Rp'.number_format($t->nilai_bagi_hasil, 0, ',', '.')) : null,
            ];
        })->values();
    }

    /**
     * Saldo per cabang & periode.
     *
     * @param  EloquentCollection<int, Lokasi>  $lokasi
     * @param  list<string>  $periode  daftar Y-m-01
     * @return array<int, array<string, int>> [id_lokasi => [periode => saldo]]
     */
    public static function saldo(EloquentCollection $lokasi, array $periode): array
    {
        $hasil = [];
        foreach ($periode as $p) {
            foreach (self::hitung($lokasi, $p) as $rekap) {
                $hasil[$rekap['id_lokasi']][$p] = $rekap['saldo'];
            }
        }

        return $hasil;
    }

    /**
     * Saldo yang masih bisa diajukan per cabang & periode: saldo dikurangi pengajuan yang masih pending.
     *
     * @param  EloquentCollection<int, Lokasi>  $lokasi
     * @param  list<string>  $periode  daftar Y-m-01
     * @return array<int, array<string, int>> [id_lokasi => [periode => saldo tersedia]]
     */
    public static function saldoTersedia(EloquentCollection $lokasi, array $periode): array
    {
        $pending = PenarikanSaldo::whereIn('id_lokasi', $lokasi->modelKeys())->whereIn('periode_sumber', $periode)->where('status', 'pending')
            ->groupBy('id_lokasi', 'periode_sumber')->selectRaw('id_lokasi, periode_sumber, SUM(nominal) as total')->get()
            ->mapWithKeys(fn ($p) => ["{$p->id_lokasi}|{$p->periode_sumber}" => (int) $p->total]);

        return collect(self::saldo($lokasi, $periode))
            ->map(fn (array $perPeriode, int $id) => collect($perPeriode)->map(fn (int $saldo, string $p) => $saldo - $pending->get("{$id}|{$p}", 0))->all())
            ->all();
    }
}
