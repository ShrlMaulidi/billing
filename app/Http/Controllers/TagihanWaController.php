<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\PesanWa;
use App\Services\WhatsApp\GagalWhatsApp;
use App\Services\WhatsApp\WaGateway;
use App\Support\NomorHp;
use App\Support\PesanTagihan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Kirim tagihan satu pelanggan via WhatsApp (ikon lonceng di Pelanggan Belum Lunas). Pesan dikirim dari WhatsApp cabang
 * pelanggan memakai template cabang itu (menu Notifikasi), lalu dicatat di pesan_wa (terkirim / gagal).
 */
class TagihanWaController extends Controller
{
    public function __construct(private WaGateway $wa) {}

    /** Pratinjau pesan sebelum dikirim: nomor tujuan, isi pesan, status WhatsApp cabang, dan kapan terakhir dikirim. */
    public function pratinjau(Request $request, Pelanggan $pelanggan): JsonResponse
    {
        $bulan = $this->bulanTagihan($request, $pelanggan);
        $status = null;
        try {
            $status = $this->wa->status($pelanggan->id_lokasi)['status'];
        } catch (GagalWhatsApp) {
            // Gateway mati: pratinjau tetap tampil, tombol Kirim memberi pesan saat dicoba
        }

        return response()->json([
            'nomor' => NomorHp::normalkan((string) $pelanggan->tlp),
            'pesan' => PesanTagihan::untuk($pelanggan, $bulan),
            'cabang' => $pelanggan->lokasi?->nama_lokasi,
            'wa_terhubung' => $status === 'connected',
            'terakhir' => PesanWa::where(['pelanggan_id' => $pelanggan->id, 'jenis' => 'tagihan', 'status' => 'terkirim'])
                ->whereDate('periode', $bulan)->latest('id')->value('created_at')?->toDateTimeString(),
        ]);
    }

    public function kirim(Request $request, Pelanggan $pelanggan): JsonResponse
    {
        $bulan = $this->bulanTagihan($request, $pelanggan);
        // Cegah klik ganda / dua admin mengirim tagihan yang sama bersamaan
        $kunci = Cache::lock("wa-tagihan:{$pelanggan->id}:{$bulan}", 30);
        if (! $kunci->get()) {
            return response()->json(['message' => 'Tagihan ini sedang dikirim, tunggu sebentar'], 429);
        }

        try {
            $nomor = NomorHp::normalkan((string) $pelanggan->tlp);
            $isi = PesanTagihan::untuk($pelanggan, $bulan);
            $pesan = PesanWa::create([
                'id_lokasi' => $pelanggan->id_lokasi, 'pelanggan_id' => $pelanggan->id, 'user_id' => $request->user()->id, 'jenis' => 'tagihan',
                'periode' => $bulan, 'nomor' => $nomor, 'isi' => $isi, 'status' => 'gagal',
            ]);
            try {
                $pesan->update(['status' => 'terkirim', 'wa_id' => $this->wa->kirim($pelanggan->id_lokasi, NomorHp::internasional($nomor), $isi)]);
            } catch (GagalWhatsApp $e) {
                $pesan->update(['keterangan' => mb_substr($e->getMessage(), 0, 250)]);
                Log::warning('Kirim tagihan WhatsApp gagal', ['pelanggan_id' => $pelanggan->id, 'periode' => $bulan, 'error' => $e->getMessage()]);

                return response()->json(['message' => $e->getMessage()], 422);
            }
        } finally {
            $kunci->release();
        }

        return response()->json(['message' => "Tagihan terkirim ke WhatsApp {$pelanggan->nama} ({$nomor})", 'terakhir' => $pesan->updated_at->toDateTimeString()]);
    }

    /**
     * Bulan tagihan (Y-m-01) dari filter halaman, setelah memastikan pelanggan boleh diakses, aktif, punya nomor WhatsApp,
     * dan tagihan bulan itu memang belum dibayar.
     */
    private function bulanTagihan(Request $request, Pelanggan $pelanggan): string
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);
        $data = $request->validate(['bulan' => ['required', 'integer', 'between:1,12'], 'tahun' => ['required', 'integer', 'between:2020,2100']], $this->pesanValidasi());
        $bulan = sprintf('%04d-%02d-01', $data['tahun'], $data['bulan']);
        $pelanggan->loadMissing(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi']);

        $tolak = match (true) {
            ! $pelanggan->id_lokasi => 'Pelanggan belum terdaftar di cabang mana pun',
            $pelanggan->status !== 'aktif' => 'Pelanggan sudah berhenti berlangganan',
            ! $pelanggan->paket || $pelanggan->paket->harga <= 0 => 'Pelanggan tidak punya tagihan (paket gratis / belum ada paket)',
            ! NomorHp::sah((string) $pelanggan->tlp) => 'Nomor HP pelanggan kosong atau tidak valid. Perbaiki di Data Pelanggan.',
            $pelanggan->pembayaran()->where('periode', $pelanggan->periodePemakaian($bulan))->exists() => 'Tagihan bulan ini sudah lunas',
            default => null,
        };
        abort_if($tolak !== null, 422, $tolak);

        return $bulan;
    }
}
