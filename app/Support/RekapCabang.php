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
 * Rekap keuangan per cabang per periode; dipakai Dashboard (Mitra & Cabang), Penarikan Saldo, Pembayaran Masuk, dan Laporan agar angka saldo selalu sama.
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
     * @param  bool  $hitungPelanggan  false = lewati hitungan jumlah pelanggan (kolom jumlah_pelanggan dkk. bernilai null), mis. untuk laporan keuangan
     * @return Collection<int, array<string, mixed>>
     */
    public static function hitung(EloquentCollection $lokasi, string $periode, bool $hitungPelanggan = true): Collection
    {
        return self::hitungPerPeriode($lokasi, [$periode], $hitungPelanggan)[$periode];
    }

    /**
     * Rekap banyak periode sekaligus: tarif, pembayaran, penarikan, dan setoran masing-masing diambil dengan satu query
     * untuk semua periode (pembayaran dijumlahkan di database per cabang, bulan bayar, dan metode), lalu dihitung per periode
     * dengan rumus yang sama seperti hitung().
     *
     * @param  EloquentCollection<int, Lokasi>  $lokasi
     * @param  list<string>  $periode  daftar Y-m-01
     * @param  bool  $hitungPelanggan  hitung jumlah pelanggan per periode (1 query per periode); false untuk saldo & laporan
     * @return array<string, Collection<int, array<string, mixed>>> periode => rekap per cabang
     */
    public static function hitungPerPeriode(EloquentCollection $lokasi, array $periode, bool $hitungPelanggan = false): array
    {
        if ($periode === []) {
            return [];
        }
        $id = $lokasi->modelKeys();
        $tarif = PajakCabang::berlakuPerPeriode($id, $periode);
        // SUBSTR(paid_at, 1, 7) = "Y-m" di SQLite (teks) maupun MySQL (DATETIME)
        $pembayaran = Pembayaran::whereIn('id_lokasi', $id)
            ->whereBetween('paid_at', [Carbon::parse(min($periode))->startOfDay(), Carbon::parse(max($periode))->endOfMonth()])
            ->selectRaw('id_lokasi, SUBSTR(paid_at, 1, 7) as bulan, metode, SUM(jml_bayar) as total, COUNT(*) as jumlah')
            ->groupBy('id_lokasi', 'bulan', 'metode')->toBase()->get()
            ->groupBy(fn ($b) => "{$b->id_lokasi}|{$b->bulan}-01");
        $penarikan = PenarikanSaldo::whereIn('id_lokasi', $id)->whereIn('periode_sumber', $periode)
            ->whereIn('status', PenarikanSaldo::MENGURANGI_SALDO)
            ->groupBy('id_lokasi', 'periode_sumber')->selectRaw('id_lokasi, periode_sumber, SUM(nominal) as total')->toBase()->get()
            ->mapWithKeys(fn ($p) => ["{$p->id_lokasi}|{$p->periode_sumber}" => (int) $p->total]);
        $setoran = SetoranMitra::whereIn('id_lokasi', $id)->whereIn('periode', $periode)
            ->groupBy('id_lokasi', 'periode')->selectRaw('id_lokasi, periode, SUM(nominal) as total')->toBase()->get()
            ->mapWithKeys(fn ($s) => ["{$s->id_lokasi}|{$s->periode}" => (int) $s->total]);

        $hasil = [];
        foreach ($periode as $p) {
            if ($hitungPelanggan) {
                $awal = Carbon::parse($p);
                $lokasi->loadCount([
                    'pelanggan as jumlah_pelanggan' => fn ($q) => $q->where('status', 'aktif'),
                    'pelanggan as pelanggan_baru' => fn ($q) => $q->whereBetween('tgl_registrasi', [$p, $awal->copy()->endOfMonth()->toDateString()]),
                    'pelanggan as pelanggan_free' => fn ($q) => $q->where('status', 'aktif')->whereHas('paket', fn ($q) => $q->where('harga', 0)),
                ]);
            }
            $hasil[$p] = $lokasi->map(function (Lokasi $l) use ($p, $tarif, $pembayaran, $penarikan, $setoran, $hitungPelanggan) {
                $t = $tarif[$p]->get($l->id);
                $bayar = $pembayaran->get("{$l->id}|{$p}", collect());
                $pemasukan = (float) $bayar->sum('total');
                $cash = (float) $bayar->where('metode', 'cash')->sum('total');
                $pg = (float) $bayar->whereIn('metode', self::PAYMENT_GATEWAY)->sum('total');
                $viaMitra = $pemasukan - ($l->admin_full ? 0 : $pg);
                $rincian = PajakCabang::rincian($t, $pemasukan, (int) $bayar->sum('jumlah'));
                $nominal = [...array_fill_keys(self::NOMINAL_KOSONG, 0), 'sudah_setor' => $setoran->get("{$l->id}|{$p}", 0), 'total_wd' => $penarikan->get("{$l->id}|{$p}", 0)];
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
                    'jumlah_pelanggan' => $hitungPelanggan ? $l->jumlah_pelanggan : null,
                    'pelanggan_baru' => $hitungPelanggan ? $l->pelanggan_baru : null,
                    'pelanggan_free' => $hitungPelanggan ? $l->pelanggan_free : null,
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

        return $hasil;
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
        // Semua periode dihitung sekaligus (4 query), tanpa jumlah pelanggan yang tidak dipakai saldo
        $hasil = [];
        foreach (self::hitungPerPeriode($lokasi, $periode) as $p => $rekapPeriode) {
            foreach ($rekapPeriode as $rekap) {
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
