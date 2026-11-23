<?php

namespace App\Http\Controllers;

use App\Models\Lokasi;
use App\Models\PesanWa;
use App\Models\TemplatePesan;
use App\Services\WhatsApp\GagalWhatsApp;
use App\Services\WhatsApp\WaGateway;
use App\Support\Audit;
use App\Support\NomorHp;
use App\Support\PesanTagihan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Notifikasi: WhatsApp per cabang (scan QR, putuskan, kirim uji) dan template kalimat tagihan per cabang.
 * User cabang hanya melihat & mengatur cabangnya sendiri; user pusat semua cabang. Tagihan dikirim dari menu Pelanggan
 * Belum Lunas (TagihanWaController) lewat WhatsApp cabang pelanggan.
 */
class NotifikasiController extends Controller
{
    public function __construct(private WaGateway $wa) {}

    public function index(Request $request): Response
    {
        $cabangUser = $request->user()->id_lokasi;
        $lokasi = Lokasi::when($cabangUser, fn ($q, $id) => $q->whereKey($id))->orderBy('nama_lokasi')->get(['id', 'nama_lokasi']);
        $sesi = $this->wa->semuaStatus();
        $template = TemplatePesan::whereIn('id_lokasi', $lokasi->pluck('id'))->where('jenis', 'tagihan')->pluck('isi', 'id_lokasi');
        $hitung = PesanWa::whereIn('id_lokasi', $lokasi->pluck('id'))->where('created_at', '>=', today()->startOfMonth())
            ->selectRaw("id_lokasi, SUM(CASE WHEN status = 'terkirim' THEN 1 ELSE 0 END) AS terkirim, SUM(CASE WHEN status = 'gagal' THEN 1 ELSE 0 END) AS gagal")
            ->groupBy('id_lokasi')->get()->keyBy('id_lokasi');

        return Inertia::render('KelolaData/Notifikasi', [
            'gateway' => ! $this->wa->dikonfigurasi() ? 'belum_dikonfigurasi' : ($sesi === null ? 'offline' : 'siap'),
            'cabang' => $lokasi->map(fn (Lokasi $l) => [
                'id' => $l->id,
                'nama' => $l->nama_lokasi,
                'wa' => $sesi === null ? null : collect($sesi[$l->id] ?? ['status' => 'disconnected'])->only(['status', 'nomor', 'nama', 'sejak', 'galat']),
                'template' => $template[$l->id] ?? null,
                'terkirim' => (int) ($hitung[$l->id]->terkirim ?? 0),
                'gagal' => (int) ($hitung[$l->id]->gagal ?? 0),
            ]),
            'templateBawaan' => TemplatePesan::BAWAAN,
            'placeholder' => collect(TemplatePesan::PLACEHOLDER)->map(fn ($ket, $kode) => ['kode' => $kode, 'keterangan' => $ket])->values(),
            'maksTemplate' => TemplatePesan::MAKS_ISI,
        ]);
    }

    /** Status WhatsApp cabang beserta teks QR (dipantau berkala selama modal scan terbuka). */
    public function status(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);

        return $this->jawab(fn () => $this->wa->status($lokasi->id));
    }

    /** Mulai sesi WhatsApp cabang: QR muncul untuk discan dari HP cabang (WhatsApp › Perangkat Tertaut). */
    public function hubungkan(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);

        return $this->jawab(function () use ($lokasi) {
            $status = $this->wa->hubungkan($lokasi->id);
            if ($status['status'] !== 'connected') {
                Audit::catat(TemplatePesan::MODUL_AUDIT, 'update', "Meminta QR untuk menghubungkan WhatsApp cabang {$lokasi->nama_lokasi}", $lokasi);
            }

            return $status;
        });
    }

    public function putuskan(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);

        return $this->jawab(function () use ($lokasi) {
            $this->wa->putuskan($lokasi->id);
            Audit::catat(TemplatePesan::MODUL_AUDIT, 'update', "Memutuskan WhatsApp cabang {$lokasi->nama_lokasi}", $lokasi);

            return ['message' => "WhatsApp cabang {$lokasi->nama_lokasi} diputuskan"];
        });
    }

    /** Kirim pesan uji ke satu nomor untuk memastikan WhatsApp cabang bisa mengirim. */
    public function uji(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);
        $request->merge(['nomor' => NomorHp::normalkan((string) $request->input('nomor'))]);
        $data = $request->validate(['nomor' => ['required', 'string', 'regex:/^0\d{9,14}$/']], [
            ...$this->pesanValidasi(), 'nomor.regex' => 'Nomor WhatsApp tidak valid (contoh 081234567890)',
        ], ['nomor' => 'Nomor WhatsApp']);

        $isi = "Pesan uji dari MSS Billing ({$lokasi->nama_lokasi}). WhatsApp cabang siap mengirim tagihan.";
        $pesan = PesanWa::create([
            'id_lokasi' => $lokasi->id, 'user_id' => $request->user()->id, 'jenis' => 'uji', 'nomor' => $data['nomor'], 'isi' => $isi, 'status' => 'gagal',
        ]);
        try {
            $pesan->update(['status' => 'terkirim', 'wa_id' => $this->wa->kirim($lokasi->id, NomorHp::internasional($data['nomor']), $isi)]);
        } catch (GagalWhatsApp $e) {
            $pesan->update(['keterangan' => mb_substr($e->getMessage(), 0, 250)]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Pesan uji terkirim ke {$data['nomor']}"]);
    }

    /** Simpan template tagihan cabang. */
    public function simpanTemplate(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);
        $data = $request->validate(['isi' => ['required', 'string', 'max:'.TemplatePesan::MAKS_ISI]], [
            ...$this->pesanValidasi(), 'isi.max' => 'Template maksimal '.TemplatePesan::MAKS_ISI.' karakter',
        ], ['isi' => 'Isi template']);

        TemplatePesan::updateOrCreate(['id_lokasi' => $lokasi->id, 'jenis' => 'tagihan'], ['isi' => str_replace("\r\n", "\n", $data['isi'])]);

        return response()->json(['message' => "Template tagihan {$lokasi->nama_lokasi} disimpan", 'contoh' => PesanTagihan::contoh($data['isi'], $lokasi->nama_lokasi)]);
    }

    /** Kembalikan template cabang ke kalimat bawaan. */
    public function hapusTemplate(Request $request, Lokasi $lokasi): JsonResponse
    {
        $this->pastikanCabang($request, $lokasi->id);
        TemplatePesan::where(['id_lokasi' => $lokasi->id, 'jenis' => 'tagihan'])->first()?->delete();

        return response()->json(['message' => "Template tagihan {$lokasi->nama_lokasi} kembali ke kalimat bawaan"]);
    }

    private function jawab(callable $aksi): JsonResponse
    {
        try {
            return response()->json($aksi());
        } catch (GagalWhatsApp $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
