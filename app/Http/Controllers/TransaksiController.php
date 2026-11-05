<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\PelangganInstansi;
use App\Models\PembayaranInstansi;
use App\Models\PenarikanSaldo;
use App\Models\Rekening;
use App\Models\SetoranMitra;
use App\Support\RekapCabang;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Penarikan saldo mitra memakai database: mitra mengajukan dari saldo satu periode (tidak bisa bila saldo 0/minus),
 * Super Admin (user semua cabang) mentransfer dana lalu menyetujui dengan foto bukti transfer sehingga saldo berkurang.
 * Setoran mitra diinput user pusat dan langsung menambah saldo mitra. Pengeluaran belum punya tabel: halamannya tampil kosong.
 */
class TransaksiController extends Controller
{
    /** Jumlah bulan terakhir (termasuk bulan berjalan) yang bisa dipilih untuk penarikan & setoran. */
    private const BULAN_TRANSAKSI = 12;

    /** Pilihan periode Pembayaran Masuk mundur sampai Januari sekian tahun lalu. */
    private const TAHUN_PEMBAYARAN_MASUK = 2;

    /** @var array<string, string> Tipe di Pembayaran Masuk (urutan pilihan form): reseller & retail = cabang, korporat & pemerintah = instansi */
    private const TIPE_PEMBAYARAN = ['reseller' => 'Mitra Reseller', 'pemerintah' => 'Instansi Pemerintah', 'korporat' => 'Korporat', 'retail' => 'Shafa.net'];

    public function penarikan(Request $request)
    {
        $f = $this->filters($request) + ['status' => $request->input('status'), 'semua' => $request->boolean('semua')];
        $periode = $this->periode($f);
        // Saldo untuk pilihan cabang di form pengajuan: user cabang hanya cabangnya, user pusat semua cabang
        $lokasi = Lokasi::when($request->user()->id_lokasi, fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get();

        return Inertia::render('Transaksi/Penarikan', [
            'filters' => $f,
            // Daftar menurut waktu pengajuan; "Semua periode" menampilkan seluruh pengajuan
            'rows' => PenarikanSaldo::with(['lokasi:id,nama_lokasi,nama_mitra', 'pemohon:id,name', 'pemroses:id,name'])
                ->when($f['cabang'], fn ($q, $id) => $q->where('id_lokasi', $id))
                ->when(in_array($f['status'], PenarikanSaldo::STATUS, true), fn ($q) => $q->where('status', $f['status']))
                ->unless($f['semua'], fn ($q) => $q->whereBetween('created_at', [Carbon::parse($periode)->startOfDay(), Carbon::parse($periode)->endOfMonth()]))
                ->latest()->get()
                ->map(fn (PenarikanSaldo $p) => [
                    ...$p->only(['id', 'id_lokasi', 'nama_request', 'periode_sumber', 'nominal', 'keterangan', 'bank_penerima', 'no_rekening_penerima', 'atas_nama_penerima', 'status', 'catatan', 'created_at', 'diproses_at']),
                    'ada_bukti' => (bool) $p->bukti_transfer,
                    'cabang' => $p->lokasi?->nama_lokasi ?? '-',
                    'nama_mitra' => $p->lokasi?->nama_mitra ?? '-',
                    'pemohon' => $p->pemohon?->name ?? '-',
                    'diproses_oleh' => $p->pemroses?->name,
                ]),
            'saldo' => (object) RekapCabang::saldoTersedia($lokasi, $this->periodeTerakhir()),
            'periodeOptions' => $this->periodeTerakhir(),
        ]);
    }

    /** Pengajuan penarikan; user cabang (mitra) hanya untuk cabangnya. Nominal harus ≤ saldo tersedia (saldo − pengajuan pending). */
    public function ajukanPenarikan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_lokasi' => ['required', 'integer', 'exists:lokasi,id', ...$this->aturanCabangSendiri($request)],
            'nama_request' => ['required', 'string', 'max:100'],
            'periode_sumber' => ['required', Rule::in($this->periodeTerakhir())],
            'nominal' => ['required', 'integer', 'min:1', 'max:4000000000'],
            'keterangan' => ['required', 'string', 'max:500'],
            'bank_penerima' => ['required', 'string', 'max:100'],
            'no_rekening_penerima' => ['required', 'string', 'max:50', 'regex:/^[0-9 .\-]+$/'],
            'atas_nama_penerima' => ['required', 'string', 'max:100'],
        ], $this->pesanValidasi(), [
            'id_lokasi' => 'Mitra / cabang', 'nama_request' => 'Nama request', 'periode_sumber' => 'Periode sumber', 'nominal' => 'Nominal',
            'keterangan' => 'Keterangan', 'bank_penerima' => 'Nama bank / e-wallet', 'no_rekening_penerima' => 'Nomor rekening', 'atas_nama_penerima' => 'Atas nama',
        ]);

        $penarikan = DB::transaction(function () use ($data, $request) {
            // Kunci baris cabang agar dua pengajuan bersamaan tidak sama-sama lolos cek saldo
            $lokasi = Lokasi::lockForUpdate()->findOrFail($data['id_lokasi']);
            $tersedia = RekapCabang::saldoTersedia($lokasi->newCollection([$lokasi]), [$data['periode_sumber']])[$lokasi->id][$data['periode_sumber']];
            if ($tersedia <= 0) {
                throw ValidationException::withMessages(['nominal' => 'Saldo periode ini Rp'.number_format($tersedia, 0, ',', '.').', tidak bisa melakukan penarikan']);
            }
            if ($data['nominal'] > $tersedia) {
                throw ValidationException::withMessages(['nominal' => 'Nominal melebihi saldo tersedia Rp'.number_format($tersedia, 0, ',', '.')]);
            }

            return PenarikanSaldo::create([...$data, 'user_id' => $request->user()->id, 'status' => 'pending']);
        });

        return response()->json(['message' => "Pengajuan penarikan {$penarikan->nama_request} dikirim, menunggu persetujuan Super Admin"]);
    }

    /**
     * Setujui pengajuan pending setelah dana ditransfer: foto bukti transfer wajib, saldo periode sumber berkurang.
     * Ditolak bila saldo cabang saat ini sudah tidak cukup. Foto disimpan di disk privat, bukan folder public.
     */
    public function setujuiPenarikan(Request $request, PenarikanSaldo $penarikan): JsonResponse
    {
        $data = $request->validate([
            'bukti_transfer' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ], $this->pesanValidasi() + [
            'bukti_transfer.image' => 'Bukti transfer harus berupa foto',
            'bukti_transfer.mimes' => 'Bukti transfer harus berformat JPG, PNG, atau WEBP',
            'bukti_transfer.max' => 'Ukuran foto bukti transfer maksimal 2 MB',
        ], ['bukti_transfer' => 'Foto bukti transfer', 'catatan' => 'Catatan']);

        $path = null;
        try {
            $gagal = DB::transaction(function () use ($request, $penarikan, $data, &$path) {
                $lokasi = Lokasi::lockForUpdate()->findOrFail($penarikan->id_lokasi);
                $penarikan = PenarikanSaldo::lockForUpdate()->findOrFail($penarikan->id);
                if ($penarikan->status !== 'pending') {
                    return 'Pengajuan ini sudah diproses';
                }
                // Saldo rekap belum dikurangi pengajuan pending mana pun, termasuk yang ini
                $saldo = RekapCabang::hitung($lokasi->newCollection([$lokasi]), $penarikan->periode_sumber)->first()['saldo'];
                if ($penarikan->nominal > $saldo) {
                    return 'Saldo cabang saat ini Rp'.number_format($saldo, 0, ',', '.').', tidak cukup untuk penarikan ini';
                }
                $path = $data['bukti_transfer']->store(PenarikanSaldo::FOLDER_BUKTI, 'local');
                $penarikan->update([
                    'status' => 'disetujui', 'bukti_transfer' => $path, 'catatan' => $data['catatan'] ?? null,
                    'diproses_oleh' => $request->user()->id, 'diproses_at' => now(),
                ]);
                Log::info('Penarikan saldo disetujui', ['penarikan_id' => $penarikan->id, 'id_lokasi' => $penarikan->id_lokasi, 'nominal' => $penarikan->nominal, 'oleh' => $request->user()->id]);

                return null;
            });
        } catch (Throwable $e) {
            // Simpan ke database gagal: jangan tinggalkan file bukti yatim
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return $gagal
            ? response()->json(['message' => $gagal], 422)
            : response()->json(['message' => "Penarikan {$penarikan->nama_request} disetujui, saldo mitra berkurang Rp".number_format($penarikan->nominal, 0, ',', '.')]);
    }

    public function tolakPenarikan(Request $request, PenarikanSaldo $penarikan): JsonResponse
    {
        $data = $request->validate(['catatan' => ['required', 'string', 'max:255']], $this->pesanValidasi(), ['catatan' => 'Alasan penolakan']);

        // Dikunci agar pengajuan tidak diproses dua kali bersamaan
        $berhasil = DB::transaction(function () use ($request, $penarikan, $data) {
            $penarikan = PenarikanSaldo::lockForUpdate()->findOrFail($penarikan->id);
            if ($penarikan->status !== 'pending') {
                return false;
            }
            $penarikan->update(['status' => 'ditolak', 'catatan' => $data['catatan'], 'diproses_oleh' => $request->user()->id, 'diproses_at' => now()]);

            return true;
        });

        return $berhasil
            ? response()->json(['message' => "Penarikan {$penarikan->nama_request} ditolak"])
            : response()->json(['message' => 'Pengajuan ini sudah diproses'], 422);
    }

    /** Foto bukti transfer; hanya untuk user yang berhak atas cabang penarikan (mitra cabang itu atau user pusat). */
    public function buktiPenarikan(Request $request, PenarikanSaldo $penarikan): StreamedResponse
    {
        $this->pastikanCabang($request, $penarikan->id_lokasi);
        abort_unless($penarikan->bukti_transfer && Storage::disk('local')->exists($penarikan->bukti_transfer), 404);

        return Storage::disk('local')->response($penarikan->bukti_transfer, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * Pembayaran Masuk: setoran Mitra Reseller & Shafa.net (menambah saldo cabang) digabung dengan pembayaran
     * Korporat & Instansi Pemerintah. User cabang (mitra) hanya melihat setoran cabangnya; pembayaran instansi khusus user pusat.
     */
    public function setor(Request $request)
    {
        $pusat = $request->user()->id_lokasi === null;
        $f = $this->filters($request) + [
            'semua' => $request->boolean('semua'),
            'tipe' => array_key_exists((string) $request->input('tipe'), self::TIPE_PEMBAYARAN) ? $request->input('tipe') : null,
        ];
        $periode = $this->periode($f);
        $lokasi = Lokasi::when($request->user()->id_lokasi, fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get();

        $setoran = $f['tipe'] === null || isset(Lokasi::TIPE[$f['tipe']])
            ? SetoranMitra::with(['lokasi:id,tipe,nama_lokasi,nama_mitra', 'rekening', 'petugas:id,name'])
                ->when($f['cabang'], fn ($q, $id) => $q->where('id_lokasi', $id))
                ->when($f['tipe'], fn ($q, $tipe) => $q->whereRelation('lokasi', 'tipe', $tipe))
                ->unless($f['semua'], fn ($q) => $q->where('periode', $periode))
                ->latest()->get()
                ->map(fn (SetoranMitra $s) => [
                    ...$s->only(['id', 'id_lokasi', 'periode', 'nominal', 'metode', 'keterangan', 'created_at']),
                    'key' => "setoran-{$s->id}", 'sumber' => 'setoran', 'tipe' => $s->lokasi?->tipe,
                    'nama' => $s->lokasi?->nama_lokasi ?? '-',
                    'nama_mitra' => $s->lokasi?->nama_mitra,
                    'ppn' => null, 'total' => $s->nominal,
                    'rekening' => $s->rekening?->label(),
                    'ada_bukti' => (bool) $s->bukti_setor,
                    'petugas' => $s->petugas?->name ?? '-',
                ])
            : collect();

        // Pembayaran instansi tidak terikat cabang: tidak tampil saat filter cabang dipakai
        $instansi = $pusat && ! $f['cabang'] && ($f['tipe'] === null || isset(PelangganInstansi::TIPE[$f['tipe']]))
            ? PembayaranInstansi::with(['pelanggan:id,tipe,nama', 'petugas:id,name'])
                ->when($f['tipe'], fn ($q, $tipe) => $q->whereRelation('pelanggan', 'tipe', $tipe))
                ->unless($f['semua'], fn ($q) => $q->where('periode', $periode))
                ->latest()->get()
                ->map(fn (PembayaranInstansi $p) => [
                    ...$p->only(['id', 'periode', 'nominal', 'ppn_persen', 'ppn', 'total', 'created_at']),
                    'key' => "instansi-{$p->id}", 'sumber' => 'instansi', 'tipe' => $p->pelanggan->tipe, 'nama' => $p->pelanggan->nama,
                    'nama_mitra' => null, 'metode' => null, 'rekening' => null, 'keterangan' => null, 'ada_bukti' => false,
                    'petugas' => $p->petugas?->name ?? '-',
                ])
            : collect();

        return Inertia::render('Transaksi/Setor', [
            'filters' => $f,
            'rows' => $setoran->concat($instansi)->sortByDesc('created_at')->values(),
            // Kewajiban = saldo minus (uang perusahaan yang masih dipegang mitra), 0 bila saldo tidak minus.
            // Dihitung per cabang × periode (puluhan bulan), jadi dimuat setelah halaman tampil
            'kewajiban' => Inertia::defer(fn () => (object) collect(RekapCabang::saldo($lokasi, $this->periodePembayaranMasuk(bayarDiMuka: false)))
                ->map(fn (array $perPeriode) => array_map(fn (int $saldo) => max(0, -$saldo), $perPeriode))->all()),
            'periodeOptions' => [
                'cabang' => $this->periodePembayaranMasuk(bayarDiMuka: false),
                'instansi' => $this->periodePembayaranMasuk(bayarDiMuka: true),
            ],
            'periodeSekarang' => today()->format('Y-m-01'),
            'ppnBawaan' => PembayaranInstansi::PPN_BAWAAN,
            'rekeningOptions' => Rekening::whereNull('id_lokasi')->orderBy('nama_rekening')->get()
                ->map(fn (Rekening $r) => ['value' => (string) $r->id, 'label' => $r->label()])->prepend(['value' => 'cash', 'label' => 'Cash (diterima di kantor pusat)']),
            'tipeOptions' => collect(self::TIPE_PEMBAYARAN)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            // Pilihan nama per tipe untuk form tambah (hanya user pusat yang boleh menambah)
            'namaOptions' => (object) ($pusat ? $this->namaOptions() : []),
        ]);
    }

    /** Pembayaran korporat / instansi pemerintah; PPN & total dihitung di server dari nominal × PPN %. */
    public function simpanPembayaranInstansi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipe' => ['required', Rule::in(array_keys(PelangganInstansi::TIPE))],
            'pelanggan_instansi_id' => ['required', 'integer', Rule::exists('pelanggan_instansi', 'id')->where('tipe', $request->input('tipe'))],
            'nominal' => ['required', 'integer', 'min:1', 'max:4000000000'],
            'ppn_persen' => ['required', 'numeric', 'between:0,100'],
            'periode' => ['required', Rule::in($this->periodePembayaranMasuk(bayarDiMuka: true))],
        ], $this->pesanValidasi(), [
            'tipe' => 'Tipe pelanggan', 'pelanggan_instansi_id' => 'Nama pelanggan', 'nominal' => 'Nominal pembayaran', 'ppn_persen' => 'PPN', 'periode' => 'Periode',
        ]);

        $ppn = (int) round($data['nominal'] * $data['ppn_persen'] / 100);
        $pembayaran = PembayaranInstansi::create([
            ...collect($data)->except('tipe')->all(),
            'ppn' => $ppn,
            'total' => $data['nominal'] + $ppn,
            'user_id' => $request->user()->id,
        ]);
        Log::info('Pembayaran instansi dicatat', ['pembayaran_instansi_id' => $pembayaran->id, 'total' => $pembayaran->total, 'oleh' => $request->user()->id]);

        return response()->json(['message' => 'Pembayaran '.$pembayaran->pelanggan->nama.' Rp'.number_format($pembayaran->total, 0, ',', '.').' dicatat']);
    }

    /** Batalkan pembayaran korporat / instansi yang salah input. */
    public function hapusPembayaranInstansi(Request $request, PembayaranInstansi $pembayaran): JsonResponse
    {
        $pembayaran->delete();
        Log::warning('Pembayaran instansi dibatalkan', ['pembayaran_instansi_id' => $pembayaran->id, 'total' => $pembayaran->total, 'oleh' => $request->user()->id]);

        return response()->json(['message' => 'Pembayaran '.$pembayaran->pelanggan->nama.' Rp'.number_format($pembayaran->total, 0, ',', '.').' dibatalkan']);
    }

    /** @return array<string, list<array{value: int, label: string, harga?: int}>> tipe => pilihan nama */
    private function namaOptions(): array
    {
        $cabang = Lokasi::orderBy('nama_lokasi')->get(['id', 'tipe', 'nama_lokasi'])->groupBy('tipe');
        $instansi = PelangganInstansi::orderBy('nama')->get(['id', 'tipe', 'nama', 'harga', 'status'])->groupBy('tipe');

        return collect(self::TIPE_PEMBAYARAN)->map(fn ($label, string $tipe) => isset(Lokasi::TIPE[$tipe])
            ? $cabang->get($tipe, collect())->map(fn (Lokasi $l) => ['value' => $l->id, 'label' => $l->nama_lokasi])->values()->all()
            : $instansi->get($tipe, collect())->map(fn (PelangganInstansi $p) => [
                'value' => $p->id, 'label' => $p->nama.($p->status === 'berhenti' ? ' (Berhenti)' : ''), 'harga' => $p->harga,
            ])->values()->all())->all();
    }

    /** Input setoran mitra oleh user pusat: langsung menambah saldo mitra pada periode kewajibannya. */
    public function simpanSetoran(Request $request): JsonResponse
    {
        $rekeningPusat = Rekening::whereNull('id_lokasi')->pluck('id')->map(fn ($id) => (string) $id)->prepend('cash')->all();
        $data = $request->validate([
            'id_lokasi' => ['required', 'integer', 'exists:lokasi,id'],
            'periode' => ['required', Rule::in($this->periodePembayaranMasuk(bayarDiMuka: false))],
            'nominal' => ['required', 'integer', 'min:1', 'max:4000000000'],
            'metode' => ['required', 'string', Rule::in($rekeningPusat)],
            'bukti_setor' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ], $this->pesanValidasi() + [
            'bukti_setor.image' => 'Bukti setor harus berupa foto',
            'bukti_setor.mimes' => 'Bukti setor harus berformat JPG, PNG, atau WEBP',
            'bukti_setor.max' => 'Ukuran foto bukti setor maksimal 2 MB',
        ], ['id_lokasi' => 'Cabang / mitra', 'periode' => 'Periode', 'nominal' => 'Nominal disetor', 'metode' => 'Rekening pusat / cash', 'bukti_setor' => 'Bukti setor', 'keterangan' => 'Keterangan']);

        $path = $request->file('bukti_setor')?->store(SetoranMitra::FOLDER_BUKTI, 'local');
        try {
            $setoran = SetoranMitra::create([
                ...collect($data)->except(['metode', 'bukti_setor'])->all(),
                'metode' => $data['metode'] === 'cash' ? 'cash' : 'transfer',
                'rekening_id' => $data['metode'] === 'cash' ? null : (int) $data['metode'],
                'bukti_setor' => $path,
                'user_id' => $request->user()->id,
            ]);
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
        Log::info('Setoran mitra dicatat', ['setoran_id' => $setoran->id, 'id_lokasi' => $setoran->id_lokasi, 'nominal' => $setoran->nominal, 'oleh' => $request->user()->id]);

        return response()->json(['message' => 'Setoran '.$setoran->lokasi->nama_lokasi.' Rp'.number_format($setoran->nominal, 0, ',', '.').' dicatat, saldo mitra bertambah']);
    }

    /** Batalkan setoran yang salah input; saldo mitra kembali berkurang. */
    public function hapusSetoran(Request $request, SetoranMitra $setoran): JsonResponse
    {
        $setoran->delete();
        if ($setoran->bukti_setor) {
            Storage::disk('local')->delete($setoran->bukti_setor);
        }
        Log::warning('Setoran mitra dibatalkan', ['setoran_id' => $setoran->id, 'id_lokasi' => $setoran->id_lokasi, 'nominal' => $setoran->nominal, 'oleh' => $request->user()->id]);

        return response()->json(['message' => 'Setoran Rp'.number_format($setoran->nominal, 0, ',', '.').' dibatalkan, saldo mitra berkurang kembali']);
    }

    /** Foto bukti setor; hanya untuk user yang berhak atas cabang setoran (mitra cabang itu atau user pusat). */
    public function buktiSetoran(Request $request, SetoranMitra $setoran): StreamedResponse
    {
        $this->pastikanCabang($request, $setoran->id_lokasi);
        abort_unless($setoran->bukti_setor && Storage::disk('local')->exists($setoran->bukti_setor), 404);

        return Storage::disk('local')->response($setoran->bukti_setor, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function pengeluaran(Request $request)
    {
        return Inertia::render('Transaksi/Pengeluaran', [
            'filters' => $this->filters($request),
            'rows' => [],
            'ringkasan' => ['pemasukan' => 0, 'pengeluaran' => 0, 'balance' => 0],
            'master' => [],
            'rekeningOptions' => [],
        ]);
    }

    /**
     * Periode Pembayaran Masuk, dari Januari TAHUN_PEMBAYARAN_MASUK tahun lalu. Setoran Mitra / Shafa.net sampai bulan berjalan
     * (kewajibannya baru ada setelah bulannya berjalan); korporat & instansi bisa bayar di muka sampai Desember tahun depan.
     *
     * @return list<string> Y-m-01, terlama dulu
     */
    private function periodePembayaranMasuk(bool $bayarDiMuka): array
    {
        $awal = today()->startOfYear()->subYears(self::TAHUN_PEMBAYARAN_MASUK);
        $akhir = $bayarDiMuka ? today()->addYear()->endOfYear()->startOfMonth() : today()->startOfMonth();

        return array_map(fn (Carbon $bulan) => $bulan->format('Y-m-d'), CarbonPeriod::create($awal, '1 month', $akhir)->toArray());
    }

    /** @return list<string> Y-m-01 bulan yang bisa dipilih untuk penarikan saldo, terbaru dulu */
    private function periodeTerakhir(): array
    {
        return array_map(fn (int $i) => today()->startOfMonth()->subMonths($i)->format('Y-m-d'), range(0, self::BULAN_TRANSAKSI - 1));
    }
}
