<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Rekening;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Input, riwayat, edit, kwitansi, dan pembatalan pembayaran tagihan pelanggan per periode (bulan). */
class PembayaranController extends Controller
{
    /** Batas pelanggan per input pembayaran massal. */
    private const MAKS_MASSAL = 200;

    /** Riwayat pembayaran satu pelanggan (terbaru dulu) untuk modal Riwayat Pembayaran. */
    public function index(Request $request, Pelanggan $pelanggan): JsonResponse
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);

        return response()->json([
            'data' => $pelanggan->pembayaran()->with(['rekening', 'petugas:id,name'])->orderByDesc('periode')->get()
                ->map(fn (Pembayaran $p) => [
                    ...$p->only(['id', 'no_invoice', 'periode', 'jns_tagihan', 'nama_paket', 'harga', 'is_prorate', 'diskon_tipe', 'diskon_nilai', 'diskon', 'jml_bayar', 'metode', 'rekening_id', 'paid_at']),
                    'periode_pembayaran' => $p->periodePembayaran(),
                    'rekening' => $p->rekening?->label(),
                    'petugas' => $p->petugas?->name,
                ]),
        ]);
    }

    /**
     * Harga diambil dari paket di database dan diskon dihitung ulang di server, bukan dari nilai yang dikirim form.
     * Metode: "cash" (bila cabang menerima cash) atau id rekening milik cabang pelanggan / semua cabang.
     */
    public function store(Request $request, Pelanggan $pelanggan): JsonResponse
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);
        $pelanggan->load(['paket:id,nama_paket,harga', 'lokasi:id,terima_cash']);
        if ($pelanggan->status !== 'aktif' || ! $pelanggan->paket) {
            return response()->json(['message' => "Pelanggan {$pelanggan->nama} tidak aktif atau belum punya paket"], 422);
        }

        // Bulan registrasi pelanggan berskema prorate ditagih per hari, bulan lain harga penuh
        $data = $this->validated($request, $pelanggan->id_lokasi, $pelanggan->lokasi?->terima_cash !== false, $pelanggan->tagihanUntuk(...), $pelanggan->id);

        try {
            $pembayaran = $this->catat($pelanggan, $data, $request);
        } catch (UniqueConstraintViolationException) {
            // Dua kali klik simpan bersamaan: yang kedua tertahan unique (pelanggan_id, periode)
            throw ValidationException::withMessages(['bulan' => 'Periode ini sudah dibayar']);
        }

        return response()->json([
            'message' => "Pembayaran {$pelanggan->nama} periode {$this->namaPeriode($pembayaran)} sebesar {$this->rupiah($pembayaran->jml_bayar)} tersimpan ({$pembayaran->no_invoice})",
        ]);
    }

    /**
     * Input pembayaran banyak pelanggan sekaligus (halaman Pelanggan Belum Lunas): tiap pelanggan membayar tagihan
     * yang muncul di bulan tagihan terpilih (pascabayar = pemakaian bulan sebelumnya) dengan harga penuh/prorate
     * dari server, tanpa diskon. Pelanggan yang sudah lunas atau metodenya tidak berlaku di cabangnya dilewati.
     */
    public function massal(Request $request): JsonResponse
    {
        $input = $request->validate([
            'pelanggan_ids' => ['required', 'array', 'min:1', 'max:'.self::MAKS_MASSAL],
            'pelanggan_ids.*' => ['integer', 'distinct'],
            'bulan' => ['required', 'integer', 'between:1,12'],
            'tahun' => ['required', 'integer', Rule::in(Pembayaran::tahunBayar())],
            'metode' => ['required', 'string', Rule::in(Rekening::pluck('id')->map(fn ($id) => (string) $id)->prepend('cash')->all())],
        ], [...$this->pesanValidasi(), 'pelanggan_ids.max' => 'Maksimal '.self::MAKS_MASSAL.' pelanggan sekali input'],
            ['pelanggan_ids' => 'Pelanggan', 'bulan' => 'Bulan', 'tahun' => 'Tahun', 'metode' => 'Metode pembayaran']);

        $ids = $input['pelanggan_ids'];
        $semua = Pelanggan::whereIn('id', $ids)->with(['paket:id,nama_paket,harga', 'lokasi:id,terima_cash'])->orderBy('nama')->get();
        // Satu saja pelanggan cabang lain (atau tidak ada) → tolak semuanya
        abort_if($semua->count() !== count($ids) || $semua->contains(fn (Pelanggan $p) => ! $request->user()->bolehAksesCabang($p->id_lokasi)), 404);

        $bulanTagihan = sprintf('%04d-%02d-01', $input['tahun'], $input['bulan']);
        $belumLunas = Pelanggan::belumLunas($bulanTagihan)->whereIn('id', $ids)->pluck('id')->flip();
        $rekening = $input['metode'] === 'cash' ? null : Rekening::find((int) $input['metode']);
        [$berhasil, $total, $dilewati] = [0, 0, []];

        foreach ($semua as $pelanggan) {
            $alasan = match (true) {
                ! $belumLunas->has($pelanggan->id) => 'sudah lunas / tidak ada tagihan',
                ! $rekening && $pelanggan->lokasi?->terima_cash === false => 'cabang tidak menerima cash',
                $rekening && $rekening->id_lokasi !== null && $rekening->id_lokasi !== $pelanggan->id_lokasi => 'rekening bukan milik cabangnya',
                default => null,
            };
            if ($alasan) {
                $dilewati[] = "{$pelanggan->nama} ($alasan)";

                continue;
            }
            $periode = $pelanggan->periodePemakaian($bulanTagihan);
            $harga = $pelanggan->tagihanUntuk($periode);
            try {
                $this->catat($pelanggan, [
                    'periode' => $periode, 'harga' => $harga, 'metode' => $rekening ? 'transfer' : 'cash', 'rekening_id' => $rekening?->id,
                    'diskon_tipe' => null, 'diskon_nilai' => 0, 'diskon' => 0, 'jml_bayar' => $harga,
                ], $request);
                $berhasil++;
                $total += $harga;
            } catch (UniqueConstraintViolationException) {
                $dilewati[] = "{$pelanggan->nama} (sudah dibayar)";
            }
        }

        $pesan = "$berhasil pembayaran tersimpan, total {$this->rupiah($total)}";

        return response()->json($dilewati
            ? ['message' => "$pesan. Dilewati: ".implode(', ', $dilewati), 'type' => 'warning', 'berhasil' => $berhasil, 'dilewati' => $dilewati]
            : ['message' => $pesan, 'berhasil' => $berhasil, 'dilewati' => []]);
    }

    /**
     * Mengubah periode, metode, atau diskon. Harga tetap harga saat dibayar; no. invoice tidak berubah.
     * Nilai sebelum & sesudah dicatat di log aplikasi.
     */
    public function update(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        $this->pastikanCabang($request, $pembayaran->id_lokasi);
        $pembayaran->load('lokasi:id,terima_cash');
        $data = $this->validated($request, $pembayaran->id_lokasi, $pembayaran->lokasi?->terima_cash !== false, $pembayaran->harga, $pembayaran->pelanggan_id, $pembayaran->id);
        unset($data['harga']);
        $sebelum = $pembayaran->only(array_keys($data));

        try {
            $pembayaran->update($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['bulan' => 'Periode ini sudah dibayar']);
        }
        Log::warning('Pembayaran diubah', [
            'no_invoice' => $pembayaran->no_invoice, 'sebelum' => $sebelum, 'sesudah' => $pembayaran->only(array_keys($data)),
            'oleh' => $request->user()->username, 'ip' => $request->ip(),
        ]);

        return response()->json(['message' => "Pembayaran {$pembayaran->no_invoice} diperbarui: periode {$this->namaPeriode($pembayaran)}, {$this->rupiah($pembayaran->jml_bayar)}"]);
    }

    public function kwitansi(Request $request, Pembayaran $pembayaran)
    {
        $this->pastikanCabang($request, $pembayaran->id_lokasi);
        $pembayaran->load(['pelanggan:id,id_pelanggan,alamat,tlp', 'lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi', 'rekening', 'petugas:id,name']);

        return Inertia::render('Pelanggan/Kwitansi', [
            'kwitansi' => [
                ...$pembayaran->only(['id', 'no_invoice', 'periode', 'jns_tagihan', 'nama_pelanggan', 'nama_paket', 'harga', 'is_prorate', 'diskon_tipe', 'diskon_nilai', 'diskon', 'jml_bayar', 'metode', 'paid_at']),
                'periode_pembayaran' => $pembayaran->periodePembayaran(),
                'id_pelanggan' => $pembayaran->pelanggan?->id_pelanggan,
                'alamat' => $pembayaran->pelanggan?->alamat,
                'tlp' => $pembayaran->pelanggan?->tlp,
                'cabang' => $pembayaran->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
                'rekening' => $pembayaran->rekening?->label(),
                'petugas' => $pembayaran->petugas?->name,
            ],
        ]);
    }

    /**
     * Tagihan (invoice) yang muncul di bulan tagihan terpilih: pemakaian bulan itu (prabayar) atau bulan sebelumnya (pascabayar),
     * nominal penuh/prorate, jatuh tempo, dan rekening tujuan yang ditandai tampil di invoice.
     */
    public function tagihan(Request $request, Pelanggan $pelanggan)
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);
        $f = $this->filters($request);
        abort_unless(in_array($f['tahun'], Pembayaran::tahunBayar(), true) && $f['bulan'] >= 1 && $f['bulan'] <= 12, 404);
        $pelanggan->load(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi']);

        $bulanTagihan = $this->periode($f);
        $periode = $pelanggan->periodePemakaian($bulanTagihan);
        $awal = Carbon::parse($bulanTagihan);
        // Jatuh tempo lama di atas tanggal 27 dipotong ke akhir bulan
        $jatuhTempo = $pelanggan->tgl_jatuh_tempo ? $awal->copy()->day(min($pelanggan->tgl_jatuh_tempo, $awal->daysInMonth))->toDateString() : null;

        return Inertia::render('Pelanggan/Tagihan', [
            'tagihan' => [
                'no_tagihan' => sprintf('TGH%s-%05d', $awal->format('Ym'), $pelanggan->id),
                ...$pelanggan->only(['id', 'id_pelanggan', 'nama', 'alamat', 'tlp', 'jns_tagihan']),
                'nama_paket' => $pelanggan->paket?->nama_paket,
                'harga_paket' => $pelanggan->paket?->harga,
                'periode_pembayaran' => $bulanTagihan,
                'periode' => $periode,
                'nominal' => $pelanggan->tagihanUntuk($periode),
                'is_prorate' => $periode === $pelanggan->periodeProrate(),
                'hari_prorate' => $periode === $pelanggan->periodeProrate() ? "{$pelanggan->hariProrate()}/{$pelanggan->tgl_registrasi->daysInMonth}" : null,
                'jatuh_tempo' => $jatuhTempo,
                'cabang' => $pelanggan->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
                'rekening' => Rekening::where('tampil_invoice', true)->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $pelanggan->id_lokasi))
                    ->orderBy('nama_rekening')->get()->map->only(['nama_rekening', 'no_rekening', 'atas_nama']),
                // Sudah dibayar: tampil LUNAS dengan tautan kwitansi
                'pembayaran_id' => $pelanggan->pembayaran()->where('periode', $periode)->value('id'),
            ],
        ]);
    }

    /** Membatalkan pembayaran yang salah input; pelanggan kembali belum lunas untuk periode itu. Tercatat di log aplikasi. */
    public function destroy(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        $this->pastikanCabang($request, $pembayaran->id_lokasi);
        $pembayaran->delete();
        Log::warning('Pembayaran dibatalkan', [
            ...$pembayaran->only(['no_invoice', 'pelanggan_id', 'nama_pelanggan', 'periode', 'jml_bayar', 'metode']),
            'oleh' => $request->user()->username, 'ip' => $request->ip(),
        ]);

        return response()->json(['message' => "Pembayaran {$pembayaran->no_invoice} ({$pembayaran->nama_pelanggan}) dibatalkan"]);
    }

    /**
     * Validasi form pembayaran (input & edit) dan hitung kolom yang disimpan: periode, harga, metode, rekening, diskon, jml_bayar.
     *
     * @param  int|Closure(string): int  $harga  harga tetap (edit) atau harga per periode pemakaian (input, mis. prorate)
     * @return array{periode: string, harga: int, metode: string, rekening_id: ?int, diskon_tipe: ?string, diskon_nilai: float|int|string, diskon: int, jml_bayar: int}
     */
    private function validated(Request $request, ?int $idLokasi, bool $terimaCash, int|Closure $harga, ?int $pelangganId, ?int $kecualiId = null): array
    {
        // Batas diskon nominal = harga periode yang dipilih; bulan/tahun yang tidak valid tetap ditolak validasi di bawah
        if ($harga instanceof Closure) {
            $harga = $harga(sprintf('%04d-%02d-01', $request->integer('tahun'), min(max($request->integer('bulan'), 1), 12)));
        }
        $persen = $request->input('diskon_tipe') === 'persentase';
        $rekening = Rekening::where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $idLokasi))->pluck('id')->map(fn ($id) => (string) $id);
        $data = $request->validate([
            'bulan' => ['required', 'integer', 'between:1,12'],
            'tahun' => ['required', 'integer', Rule::in(Pembayaran::tahunBayar())],
            'metode' => ['required', Rule::in(($terimaCash ? $rekening->prepend('cash') : $rekening)->all())],
            'diskon_tipe' => ['nullable', 'in:nominal,persentase'],
            'diskon_nilai' => ['nullable', 'numeric', 'min:0', 'max:'.($persen ? 100 : $harga)],
        ], [
            ...$this->pesanValidasi(),
            'diskon_nilai.max' => $persen ? 'Diskon maksimal 100%' : 'Diskon tidak boleh melebihi harga paket',
        ], ['bulan' => 'Bulan', 'tahun' => 'Tahun', 'metode' => 'Metode pembayaran', 'diskon_tipe' => 'Tipe diskon', 'diskon_nilai' => 'Diskon']);

        $periode = Carbon::create($data['tahun'], $data['bulan'])->format('Y-m-d');
        $sudahDibayar = $pelangganId && Pembayaran::where('pelanggan_id', $pelangganId)->where('periode', $periode)
            ->when($kecualiId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($sudahDibayar) {
            throw ValidationException::withMessages(['bulan' => 'Periode ini sudah dibayar']);
        }
        $diskon = Pembayaran::hitungDiskon($harga, $data['diskon_tipe'] ?? null, (float) ($data['diskon_nilai'] ?? 0));

        return [
            'periode' => $periode,
            'harga' => $harga,
            'metode' => $data['metode'] === 'cash' ? 'cash' : 'transfer',
            'rekening_id' => $data['metode'] === 'cash' ? null : (int) $data['metode'],
            'diskon_tipe' => $diskon > 0 ? $data['diskon_tipe'] : null,
            'diskon_nilai' => $diskon > 0 ? $data['diskon_nilai'] : 0,
            'diskon' => $diskon,
            'jml_bayar' => $harga - $diskon,
        ];
    }

    /**
     * Simpan pembayaran beserta snapshot nama, paket, dan jenis tagihan; no. invoice dibentuk dari periode + id.
     *
     * @param  array{periode: string, harga: int, metode: string, rekening_id: ?int, diskon_tipe: ?string, diskon_nilai: mixed, diskon: int, jml_bayar: int}  $data
     *
     * @throws UniqueConstraintViolationException periode sudah dibayar (klik ganda / input bersamaan)
     */
    private function catat(Pelanggan $pelanggan, array $data, Request $request): Pembayaran
    {
        return DB::transaction(function () use ($pelanggan, $data, $request) {
            $pembayaran = $pelanggan->pembayaran()->create([
                ...$data,
                'id_lokasi' => $pelanggan->id_lokasi,
                'nama_pelanggan' => $pelanggan->nama,
                'nama_paket' => $pelanggan->paket->nama_paket,
                'jns_tagihan' => $pelanggan->jns_tagihan,
                'is_prorate' => $data['periode'] === $pelanggan->periodeProrate(),
                'user_id' => $request->user()->id,
                'paid_at' => now(),
            ]);
            $pembayaran->update(['no_invoice' => sprintf('INV%s-%05d', str_replace('-', '', substr($data['periode'], 0, 7)), $pembayaran->id)]);

            return $pembayaran;
        });
    }

    private function namaPeriode(Pembayaran $pembayaran): string
    {
        return Carbon::parse($pembayaran->periode)->locale('id')->translatedFormat('F Y');
    }

    private function rupiah(int $nominal): string
    {
        return 'Rp'.number_format($nominal, 0, ',', '.');
    }
}
