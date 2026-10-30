<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Rekening;
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
    /** Riwayat pembayaran satu pelanggan (terbaru dulu) untuk modal Riwayat Pembayaran. */
    public function index(Request $request, Pelanggan $pelanggan): JsonResponse
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);

        return response()->json([
            'data' => $pelanggan->pembayaran()->with(['rekening', 'petugas:id,name'])->orderByDesc('periode')->get()
                ->map(fn (Pembayaran $p) => [
                    ...$p->only(['id', 'no_invoice', 'periode', 'nama_paket', 'harga', 'diskon_tipe', 'diskon_nilai', 'diskon', 'jml_bayar', 'metode', 'rekening_id', 'paid_at']),
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

        $harga = $pelanggan->paket->harga;
        $data = $this->validated($request, $pelanggan->id_lokasi, $pelanggan->lokasi?->terima_cash !== false, $harga, $pelanggan->id);

        try {
            $pembayaran = DB::transaction(function () use ($pelanggan, $data, $harga, $request) {
                $pembayaran = $pelanggan->pembayaran()->create([
                    ...$data,
                    'id_lokasi' => $pelanggan->id_lokasi,
                    'nama_pelanggan' => $pelanggan->nama,
                    'nama_paket' => $pelanggan->paket->nama_paket,
                    'harga' => $harga,
                    'user_id' => $request->user()->id,
                    'paid_at' => now(),
                ]);
                $pembayaran->update(['no_invoice' => sprintf('INV%s-%05d', str_replace('-', '', substr($data['periode'], 0, 7)), $pembayaran->id)]);

                return $pembayaran;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua kali klik simpan bersamaan: yang kedua tertahan unique (pelanggan_id, periode)
            throw ValidationException::withMessages(['bulan' => 'Periode ini sudah dibayar']);
        }

        return response()->json([
            'message' => "Pembayaran {$pelanggan->nama} periode {$this->namaPeriode($pembayaran)} sebesar {$this->rupiah($pembayaran->jml_bayar)} tersimpan ({$pembayaran->no_invoice})",
        ]);
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
                ...$pembayaran->only(['id', 'no_invoice', 'periode', 'nama_pelanggan', 'nama_paket', 'harga', 'diskon_tipe', 'diskon_nilai', 'diskon', 'jml_bayar', 'metode', 'paid_at']),
                'id_pelanggan' => $pembayaran->pelanggan?->id_pelanggan,
                'alamat' => $pembayaran->pelanggan?->alamat,
                'tlp' => $pembayaran->pelanggan?->tlp,
                'cabang' => $pembayaran->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
                'rekening' => $pembayaran->rekening?->label(),
                'petugas' => $pembayaran->petugas?->name,
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
     * Validasi form pembayaran (input & edit) dan hitung kolom yang disimpan: periode, metode, rekening, diskon, jml_bayar.
     *
     * @return array{periode: string, metode: string, rekening_id: ?int, diskon_tipe: ?string, diskon_nilai: float|int|string, diskon: int, jml_bayar: int}
     */
    private function validated(Request $request, ?int $idLokasi, bool $terimaCash, int $harga, ?int $pelangganId, ?int $kecualiId = null): array
    {
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
            'metode' => $data['metode'] === 'cash' ? 'cash' : 'transfer',
            'rekening_id' => $data['metode'] === 'cash' ? null : (int) $data['metode'],
            'diskon_tipe' => $diskon > 0 ? $data['diskon_tipe'] : null,
            'diskon_nilai' => $diskon > 0 ? $data['diskon_nilai'] : 0,
            'diskon' => $diskon,
            'jml_bayar' => $harga - $diskon,
        ];
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
