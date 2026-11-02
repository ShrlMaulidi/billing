<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\PenarikanSaldo;
use App\Models\Rekening;
use App\Models\SetoranMitra;
use App\Support\RekapCabang;
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
            'saldo' => (object) RekapCabang::saldoTersedia($lokasi, RekapCabang::periodeTransaksi()),
            'periodeOptions' => RekapCabang::periodeTransaksi(),
        ]);
    }

    /** Pengajuan penarikan; user cabang (mitra) hanya untuk cabangnya. Nominal harus ≤ saldo tersedia (saldo − pengajuan pending). */
    public function ajukanPenarikan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_lokasi' => ['required', 'integer', 'exists:lokasi,id', ...$this->aturanCabangSendiri($request)],
            'nama_request' => ['required', 'string', 'max:100'],
            'periode_sumber' => ['required', Rule::in(RekapCabang::periodeTransaksi())],
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

    /** Daftar setoran mitra per periode kewajiban; user cabang (mitra) hanya melihat setoran cabangnya. */
    public function setor(Request $request)
    {
        $f = $this->filters($request) + ['semua' => $request->boolean('semua')];
        $lokasi = Lokasi::when($request->user()->id_lokasi, fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get();

        return Inertia::render('Transaksi/Setor', [
            'filters' => $f,
            'rows' => SetoranMitra::with(['lokasi:id,nama_lokasi,nama_mitra', 'rekening', 'petugas:id,name'])
                ->when($f['cabang'], fn ($q, $id) => $q->where('id_lokasi', $id))
                ->unless($f['semua'], fn ($q) => $q->where('periode', $this->periode($f)))
                ->latest()->get()
                ->map(fn (SetoranMitra $s) => [
                    ...$s->only(['id', 'id_lokasi', 'periode', 'nominal', 'metode', 'keterangan', 'created_at']),
                    'cabang' => $s->lokasi?->nama_lokasi ?? '-',
                    'nama_mitra' => $s->lokasi?->nama_mitra,
                    'rekening' => $s->rekening?->label(),
                    'ada_bukti' => (bool) $s->bukti_setor,
                    'petugas' => $s->petugas?->name ?? '-',
                ]),
            // Kewajiban = saldo minus (uang perusahaan yang masih dipegang mitra), 0 bila saldo tidak minus
            'kewajiban' => (object) collect(RekapCabang::saldo($lokasi, RekapCabang::periodeTransaksi()))
                ->map(fn (array $perPeriode) => array_map(fn (int $saldo) => max(0, -$saldo), $perPeriode))->all(),
            'periodeOptions' => RekapCabang::periodeTransaksi(),
            'rekeningOptions' => Rekening::whereNull('id_lokasi')->orderBy('nama_rekening')->get()
                ->map(fn (Rekening $r) => ['value' => (string) $r->id, 'label' => $r->label()])->prepend(['value' => 'cash', 'label' => 'Cash (diterima di kantor pusat)']),
        ]);
    }

    /** Input setoran mitra oleh user pusat: langsung menambah saldo mitra pada periode kewajibannya. */
    public function simpanSetoran(Request $request): JsonResponse
    {
        $rekeningPusat = Rekening::whereNull('id_lokasi')->pluck('id')->map(fn ($id) => (string) $id)->prepend('cash')->all();
        $data = $request->validate([
            'id_lokasi' => ['required', 'integer', 'exists:lokasi,id'],
            'periode' => ['required', Rule::in(RekapCabang::periodeTransaksi())],
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
}
