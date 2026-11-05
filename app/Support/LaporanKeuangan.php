<?php

namespace App\Support;

use App\Models\Lokasi;
use App\Models\PajakCabang;
use App\Models\Pelanggan;
use App\Models\PelangganInstansi;
use App\Models\PembayaranInstansi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan keuangan perusahaan per pelanggan & periode (menu Laporan), untuk memantau tagihan yang sudah dan belum dibayar.
 * Satu baris = satu pelanggan perusahaan; setiap periode punya tagihan (termasuk pajak), pajak, dan pembayaran masuk:
 * - Mitra Reseller: kewajiban setor mitra ke MSS (RekapCabang harus_setor: PPN + admin fee + bagi hasil + PPh).
 *   Masuk = kewajiban − sisa kewajiban (saldo minus), jadi uang payment gateway yang diterima MSS ikut dihitung.
 *   Saldo mitra = jumlah saldo per periode (pendapatan reseller − pemasukan via mitra + sudah setor − sudah WD).
 * - Shafa.net: tagihan paket pelanggan end user cabang retail (aturan sama dengan Pelanggan Belum Lunas); pembayaran berdiskon dianggap lunas.
 *   Pajak = PPN (harga sudah termasuk PPN) + PPh dari DPP, memakai tarif Pengaturan Pajak cabangnya.
 * - Korporat & Instansi Pemerintah: harga per bulan + PPN sejak bulan tanggal aktif sampai sebelum tanggal berhenti.
 *   PPN memakai tarif pembayaran periode itu (instansi pemungut bisa 0%), atau tarif bawaan bila belum dibayar.
 * Status periode: lunas (sisa 0), sebagian (sudah ada pembayaran), belum_lunas. Kelebihan bayar tidak dihitung sebagai masuk.
 */
class LaporanKeuangan
{
    /** @var array<string, string> tipe => label, urutan tampil */
    public const TIPE = ['reseller' => 'Mitra Reseller', 'pemerintah' => 'Instansi Pemerintah', 'korporat' => 'Korporat', 'retail' => 'Shafa.net'];

    public const STATUS = ['lunas', 'sebagian', 'belum_lunas'];

    /**
     * @param  list<string>  $periode  bulan tagihan Y-m-01, terlama dulu
     * @param  ?int  $cabang  batasi Mitra Reseller & Shafa.net ke satu cabang (user cabang)
     * @return Collection<int, array<string, mixed>> pelanggan yang punya tagihan di periode tersebut
     */
    public static function hitung(string $tipe, array $periode, ?int $cabang = null): Collection
    {
        if ($periode === []) {
            return collect();
        }

        return match ($tipe) {
            'reseller' => self::mitra($periode, $cabang),
            'retail' => self::retail($periode, $cabang),
            'korporat', 'pemerintah' => self::instansi($tipe, $periode),
        };
    }

    /**
     * Jumlah seluruh baris.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array{omset: int, sebelum_pajak: int, pajak: int, masuk: int, sisa: int, persen_masuk: float, persen_belum: float, jumlah: int, lunas: int, sebagian: int, belum_lunas: int}
     */
    public static function ringkasan(Collection $baris): array
    {
        $omset = (int) $baris->sum('omset');
        $masuk = (int) $baris->sum('masuk');
        $persenMasuk = $omset > 0 ? round($masuk / $omset * 100, 2) : 0.0;

        return [
            'omset' => $omset,
            'sebelum_pajak' => (int) $baris->sum('sebelum_pajak'),
            'pajak' => (int) $baris->sum('pajak'),
            'masuk' => $masuk,
            'sisa' => (int) $baris->sum('sisa'),
            'persen_masuk' => $persenMasuk,
            'persen_belum' => $omset > 0 ? round(100 - $persenMasuk, 2) : 0.0,
            'jumlah' => $baris->count(),
            ...collect(self::STATUS)->mapWithKeys(fn (string $s) => [$s => $baris->where('status', $s)->count()])->all(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function mitra(array $periode, ?int $cabang): Collection
    {
        $lokasi = Lokasi::where('tipe', 'reseller')->when($cabang, fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get();
        if ($lokasi->isEmpty()) {
            return collect();
        }
        $rekap = collect($periode)->mapWithKeys(fn (string $p) => [$p => RekapCabang::hitung($lokasi, $p, hitungPelanggan: false)->keyBy('id_lokasi')]);

        return $lokasi->map(fn (Lokasi $l) => self::baris('reseller', "lokasi-{$l->id}", $l->nama_lokasi, $l->nama_mitra, $l->id,
            $rekap->map(function (Collection $perCabang, string $p) use ($l) {
                $r = $perCabang[$l->id];
                $sisa = min($r['harus_setor'], max(0, -$r['saldo']));

                return ['periode' => $p, 'tagihan' => $r['harus_setor'], 'pajak' => $r['ppn'] + $r['pph'], 'masuk' => $r['harus_setor'] - $sisa, 'saldo' => $r['saldo']];
            })->values()->all()))
            ->filter(fn (array $b) => $b['omset'] > 0)->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function retail(array $periode, ?int $cabang): Collection
    {
        // Pascabayar menagih pemakaian bulan sebelumnya
        $pemakaianAwal = Carbon::parse($periode[0])->subMonth()->format('Y-m-d');
        $pelanggan = Pelanggan::with([
            'paket:id,harga', 'lokasi:id,nama_lokasi',
            'pembayaran' => fn ($q) => $q->whereBetween('periode', [$pemakaianAwal, end($periode)])->select(['id', 'pelanggan_id', 'periode', 'jml_bayar']),
        ])
            ->whereHas('lokasi', fn ($q) => $q->where('tipe', 'retail'))
            ->when($cabang, fn ($q, $id) => $q->where('id_lokasi', $id))
            ->where('status', 'aktif')->whereHas('paket', fn ($q) => $q->where('harga', '>', 0))
            ->orderBy('nama')->get();
        $idLokasi = $pelanggan->pluck('id_lokasi')->unique()->filter()->values()->all();
        $tarif = PajakCabang::berlakuPerPeriode($idLokasi, $periode);

        return $pelanggan->map(fn (Pelanggan $pl) => self::baris('retail', "pelanggan-{$pl->id}", $pl->nama, $pl->lokasi?->nama_lokasi, $pl->id_lokasi,
            collect($periode)->map(function (string $bulanTagihan) use ($pl, $tarif) {
                $pemakaian = $pl->periodePemakaian($bulanTagihan);
                // Tagihan baru muncul untuk pemakaian sejak bulan registrasi (sama dengan scope belumLunas)
                if ($pl->tgl_registrasi && $pl->tgl_registrasi->toDateString() >= Carbon::parse($pemakaian)->addMonth()->toDateString()) {
                    return null;
                }
                $bayar = $pl->pembayaran->firstWhere('periode', $pemakaian);
                $tagihan = $bayar ? (int) $bayar->jml_bayar : (int) $pl->tagihanUntuk($pemakaian);
                $t = $tarif[$bulanTagihan]->get($pl->id_lokasi);
                $dpp = $tagihan / (1 + ($t?->ppn_persen ?? 0) / 100);

                return [
                    'periode' => $bulanTagihan, 'tagihan' => $tagihan,
                    'pajak' => (int) round($tagihan - $dpp + $dpp * ($t?->pph_persen ?? 0) / 100),
                    'masuk' => $bayar ? $tagihan : 0,
                ];
            })->filter()->values()->all()))
            ->filter(fn (array $b) => $b['omset'] > 0)->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function instansi(string $tipe, array $periode): Collection
    {
        $awal = $periode[0];
        $akhir = Carbon::parse(end($periode))->endOfMonth()->toDateString();

        return PelangganInstansi::with(['pembayaran' => fn ($q) => $q->whereBetween('periode', [$awal, end($periode)])])
            ->where('tipe', $tipe)->whereDate('tanggal_aktif', '<=', $akhir)
            // Pelanggan berhenti tetap ditagih untuk bulan sebelum tanggal berhentinya
            ->where(fn ($q) => $q->where('status', 'berlangganan')
                ->orWhere(fn ($q) => $q->where('status', 'berhenti')->whereDate('tanggal_berhenti', '>', $awal)))
            ->orderBy('nama')->get()
            ->map(fn (PelangganInstansi $p) => self::baris($tipe, "instansi-{$p->id}", $p->nama, $p->kapasitas, null,
                collect($periode)->map(function (string $bulan) use ($p) {
                    $ditagih = $bulan >= $p->tanggal_aktif->format('Y-m-01')
                        && ($p->status !== 'berhenti' || $bulan < $p->tanggal_berhenti->toDateString());
                    if (! $ditagih) {
                        return null;
                    }
                    $bayar = $p->pembayaran->where('periode', $bulan);
                    $ppn = (int) round($p->harga * ($bayar->last()?->ppn_persen ?? PembayaranInstansi::PPN_BAWAAN) / 100);

                    return ['periode' => $bulan, 'tagihan' => $p->harga + $ppn, 'pajak' => $ppn, 'masuk' => (int) $bayar->sum('total')];
                })->filter()->values()->all()))
            ->filter(fn (array $b) => $b['omset'] > 0)->values();
    }

    /**
     * @param  list<array{periode: string, tagihan: int, pajak: int, masuk: int}>  $perPeriode
     * @return array<string, mixed>
     */
    private static function baris(string $tipe, string $key, string $nama, ?string $keterangan, ?int $idLokasi, array $perPeriode): array
    {
        $periode = collect($perPeriode)->filter(fn (array $p) => $p['tagihan'] > 0)->map(function (array $p) {
            $masuk = min($p['masuk'], $p['tagihan']);
            $sisa = $p['tagihan'] - $masuk;

            return [...$p, 'masuk' => $masuk, 'sisa' => $sisa, 'status' => self::status($masuk, $sisa)];
        })->values();
        $omset = (int) $periode->sum('tagihan');
        $masuk = (int) $periode->sum('masuk');
        $sisa = (int) $periode->sum('sisa');

        return [
            'key' => $key,
            'tipe' => $tipe,
            'nama' => $nama,
            'keterangan' => $keterangan,
            // Cabang mitra / lokasi Shafa.net; null untuk korporat & instansi
            'id_lokasi' => $idLokasi,
            'omset' => $omset,
            'pajak' => (int) $periode->sum('pajak'),
            'sebelum_pajak' => $omset - (int) $periode->sum('pajak'),
            'masuk' => $masuk,
            'sisa' => $sisa,
            'persen_masuk' => $omset > 0 ? round($masuk / $omset * 100, 2) : 0.0,
            // Saldo / balance mitra (RekapCabang, sama dengan Dashboard Cabang): plus = hak mitra yang masih di MSS, minus = mitra masih harus setor
            'saldo' => $tipe === 'reseller' ? (int) collect($perPeriode)->sum('saldo') : null,
            'status' => self::status($masuk, $sisa),
            'periode' => $periode->all(),
            // Periode yang belum lunas (belum dibayar sama sekali atau baru sebagian)
            'periode_belum' => $periode->where('status', '!=', 'lunas')->map(fn (array $p) => ['periode' => $p['periode'], 'status' => $p['status'], 'sisa' => $p['sisa']])->values()->all(),
        ];
    }

    private static function status(int $masuk, int $sisa): string
    {
        return match (true) {
            $sisa === 0 => 'lunas',
            $masuk > 0 => 'sebagian',
            default => 'belum_lunas',
        };
    }
}
