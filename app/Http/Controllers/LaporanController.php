<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Support\LaporanKeuangan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan keuangan perusahaan untuk direktur & manajer: omset, pajak, pembayaran masuk, sisa, dan status bayar per pelanggan,
 * per bulan atau per tahun. Semua Laporan memuat semua tipe yang boleh dilihat user (bisa difilter per tipe);
 * submenu lain langsung memuat satu tipe. Shafa.net menampilkan perbandingan lokasi dulu, lalu pelanggan lokasi yang dipilih (?lokasi=).
 * User cabang hanya melihat cabangnya; korporat & instansi khusus user pusat.
 */
class LaporanController extends Controller
{
    /** @var array<string, array{0: string, 1: string}> tipe => [judul laporan, permission menu] */
    private const LAPORAN = [
        'reseller' => ['Laporan Mitra', 'laporan.mitra'],
        'pemerintah' => ['Laporan Instansi Pemerintah', 'laporan.pemerintah'],
        'korporat' => ['Laporan Korporat', 'laporan.korporat'],
        'retail' => ['Laporan Shafa Net', 'laporan.shafanet'],
    ];

    /** Tipe yang tidak terikat cabang: hanya untuk user semua cabang. */
    private const KHUSUS_PUSAT = ['pemerintah', 'korporat'];

    public function semua(Request $request): Response
    {
        $boleh = collect(array_keys(self::LAPORAN))->filter(fn (string $tipe) => $request->user()->can(self::LAPORAN[$tipe][1].'.view')
            && ($request->user()->id_lokasi === null || ! in_array($tipe, self::KHUSUS_PUSAT, true)))->values();
        $tipe = $boleh->contains($request->input('tipe')) ? $request->input('tipe') : null;

        return $this->laporan($request, 'Semua Laporan', $tipe ? [$tipe] : $boleh->all(), $boleh->all(), $tipe);
    }

    public function mitra(Request $request): Response
    {
        return $this->laporan($request, self::LAPORAN['reseller'][0], ['reseller']);
    }

    public function shafanet(Request $request): Response
    {
        return $this->laporan($request, self::LAPORAN['retail'][0], ['retail']);
    }

    public function korporat(Request $request): Response
    {
        return $this->laporan($request, self::LAPORAN['korporat'][0], ['korporat']);
    }

    public function pemerintah(Request $request): Response
    {
        return $this->laporan($request, self::LAPORAN['pemerintah'][0], ['pemerintah']);
    }

    /**
     * @param  list<string>  $tipe  tipe yang dihitung
     * @param  list<string>  $pilihanTipe  pilihan filter tipe (kosong = halaman satu tipe)
     */
    private function laporan(Request $request, string $judul, array $tipe, array $pilihanTipe = [], ?string $tipeTerpilih = null): Response
    {
        $f = $this->filterLaporan($request);
        $periode = $this->periodeLaporan($f);

        // Shafa.net: bandingkan lokasi dulu, pelanggan ditampilkan setelah satu lokasi dipilih (daftar lokasi ringan, tanpa menghitung laporan)
        $daftarLokasi = $tipe === ['retail']
            ? Lokasi::where('tipe', 'retail')->when($f['cabang'], fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get(['id', 'kode_lokasi', 'nama_lokasi'])
            : null;
        $lokasi = $daftarLokasi?->firstWhere('id', (int) $request->input('lokasi'))?->only(['id', 'kode_lokasi', 'nama_lokasi']);

        /*
         * Perhitungan laporan dijalankan sekali dan hanya bila prop-nya diminta: permintaan lanjutan grafik tren (deferred)
         * tidak ikut menghitung ulang seluruh laporan.
         */
        $hasil = null;
        $semua = function () use (&$hasil, $tipe, $periode, $f): Collection {
            return $hasil ??= collect($tipe)->mapWithKeys(fn (string $t) => [$t => LaporanKeuangan::hitung($t, $periode, $f['cabang'])]);
        };
        $perTipe = fn (): Collection => $lokasi
            ? $semua()->merge(['retail' => $semua()['retail']->where('id_lokasi', $lokasi['id'])->values()])
            : $semua();
        $baris = fn (): Collection => $perTipe()->flatten(1);

        return Inertia::render('Laporan/Index', [
            'filters' => [...$f, 'tipe' => $tipeTerpilih, 'lokasi' => $lokasi['id'] ?? null],
            'lokasi' => $lokasi,
            'perLokasi' => $daftarLokasi ? fn () => $daftarLokasi->map(fn (Lokasi $l) => [
                'id' => $l->id, 'kode_lokasi' => $l->kode_lokasi, 'nama_lokasi' => $l->nama_lokasi,
                ...LaporanKeuangan::ringkasan($semua()['retail']->where('id_lokasi', $l->id)),
            ])->values() : null,
            'judul' => $judul,
            'periode' => $periode,
            'ringkasan' => fn () => LaporanKeuangan::ringkasan($baris()),
            'perTipe' => fn () => $perTipe()->map(fn (Collection $b, string $t) => ['tipe' => $t, ...LaporanKeuangan::ringkasan($b)])->values(),
            // Tampilan perbandingan lokasi tidak menampilkan daftar pelanggan: jangan kirim datanya
            'rows' => fn () => match (true) {
                $daftarLokasi !== null && $lokasi === null => [],
                (bool) $f['status'] => $baris()->where('status', $f['status'])->values(),
                default => $baris(),
            },
            // Grafik tren khusus Semua Laporan; dihitung untuk 12 bulan sehingga dimuat setelah halaman tampil
            'tren' => $pilihanTipe ? Inertia::defer(fn () => $this->tren($f, $tipe, $lokasi['id'] ?? null)) : null,
            'tipeOptions' => collect($pilihanTipe)->map(fn (string $t) => ['value' => $t, 'label' => LaporanKeuangan::TIPE[$t]])->values(),
            'tahunList' => $this->tahunList(),
        ]);
    }

    /**
     * Bulan tagihan yang dilaporkan: satu bulan, atau Januari sampai bulan berjalan (Desember untuk tahun lalu).
     *
     * @param  array{bulan: int, tahun: int, mode: string}  $f
     * @return list<string> Y-m-01, terlama dulu
     */
    private function periodeLaporan(array $f): array
    {
        if ($f['mode'] === 'bulanan') {
            return [$this->periode($f)];
        }
        $hariIni = today();
        $bulanTerakhir = match (true) {
            $f['tahun'] < $hariIni->year => 12,
            $f['tahun'] === $hariIni->year => $hariIni->month,
            default => 0,
        };

        return array_map(fn (int $bulan) => sprintf('%04d-%02d-01', $f['tahun'], $bulan), $bulanTerakhir ? range(1, $bulanTerakhir) : []);
    }

    /**
     * Total omset & pembayaran masuk per bulan untuk grafik naik/turun: 12 bulan sampai bulan terpilih (bulanan)
     * atau Januari sampai bulan berjalan (tahunan); bulan yang belum berjalan tidak ditampilkan.
     *
     * @param  list<string>  $tipe
     * @return list<array{periode: string, omset: int, masuk: int}>
     */
    private function tren(array $f, array $tipe, ?int $idLokasi): array
    {
        if ($f['mode'] === 'tahunan') {
            $periode = $this->periodeLaporan($f);
        } else {
            $akhir = Carbon::parse($this->periode($f))->min(today()->startOfMonth());
            $periode = array_map(fn (int $mundur) => $akhir->copy()->subMonths($mundur)->format('Y-m-d'), range(11, 0));
        }
        $total = array_fill_keys($periode, ['omset' => 0, 'masuk' => 0]);
        foreach ($tipe as $t) {
            foreach (LaporanKeuangan::hitung($t, $periode, $f['cabang']) as $baris) {
                if ($idLokasi && $baris['id_lokasi'] !== $idLokasi) {
                    continue;
                }
                foreach ($baris['periode'] as $p) {
                    $total[$p['periode']]['omset'] += $p['tagihan'];
                    $total[$p['periode']]['masuk'] += $p['masuk'];
                }
            }
        }

        return array_map(fn (string $p) => ['periode' => $p, ...$total[$p]], $periode);
    }

    /** @return array{bulan: int, tahun: int, cabang: ?int, mode: string, status: ?string} */
    private function filterLaporan(Request $request): array
    {
        $f = $this->filters($request);
        $hariIni = today();

        return [
            ...$f,
            'bulan' => $f['bulan'] >= 1 && $f['bulan'] <= 12 ? $f['bulan'] : $hariIni->month,
            'tahun' => $f['tahun'] >= 2000 && $f['tahun'] <= $hariIni->year + 1 ? $f['tahun'] : $hariIni->year,
            'mode' => $request->input('mode') === 'tahunan' ? 'tahunan' : 'bulanan',
            'status' => in_array($request->input('status'), LaporanKeuangan::STATUS, true) ? $request->input('status') : null,
        ];
    }

    /** @return Collection<int, int> tahun yang tercakup 3 tahun terakhir */
    private function tahunList(): Collection
    {
        return collect(range(2, 0))->map(fn (int $i) => today()->year - $i)->values();
    }
}
