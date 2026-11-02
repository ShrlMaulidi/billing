<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Rekening;
use App\Support\PercobaanLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Aplikasi mobile pelanggan: login dengan ID pelanggan + nomor HP terdaftar (keduanya harus cocok), lalu melihat
 * profil langganan, tagihan belum dibayar, dan riwayat pembayaran miliknya sendiri. Token berkemampuan "pelanggan".
 * Data pribadi yang tidak diperlukan (alamat, NIK, akun router) tidak dikirim, karena ID & nomor HP bukan rahasia yang kuat.
 */
class PortalPelangganController extends Controller
{
    public const MASA_TOKEN_HARI = 90;

    /** Jumlah pembayaran terakhir di riwayat. */
    private const MAKS_RIWAYAT = 24;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_pelanggan' => ['required', 'string', 'max:50'],
            'tlp' => ['required', 'string', 'max:30'],
            'device_name' => ['required', 'string', 'max:100'],
        ], $this->pesanValidasi(), ['id_pelanggan' => 'ID pelanggan', 'tlp' => 'Nomor HP', 'device_name' => 'Nama perangkat']);

        $percobaan = new PercobaanLogin('pelanggan:'.$data['id_pelanggan'], $request->ip(), 'id_pelanggan');
        $percobaan->pastikanBelumTerkunci();

        $nomor = Pelanggan::nomorHp($data['tlp']);
        $pelanggan = Pelanggan::where('status', 'aktif')->firstWhere('id_pelanggan', $data['id_pelanggan']);
        if (! $pelanggan || strlen($nomor) < 10 || Pelanggan::nomorHp((string) $pelanggan->tlp) !== $nomor) {
            $percobaan->gagal();
            Log::warning('Login pelanggan gagal', ['id_pelanggan' => $data['id_pelanggan'], 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['id_pelanggan' => 'ID pelanggan atau nomor HP tidak cocok.']);
        }

        $percobaan->berhasil();
        $token = $pelanggan->createToken($data['device_name'], ['pelanggan'], now()->addDays(self::MASA_TOKEN_HARI));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'pelanggan' => $this->profilData($pelanggan),
        ]);
    }

    public function profil(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profilData($request->user())]);
    }

    /** Invoice yang sudah muncul tetapi belum dibayar (12 bulan terakhir) + rekening tujuan transfer cabangnya. */
    public function tagihan(Request $request): JsonResponse
    {
        $pelanggan = $request->user()->load(['paket:id,nama_paket,harga', 'pembayaran:id,pelanggan_id,periode']);

        return response()->json([
            'data' => $pelanggan->tagihanBelumDibayar(),
            'rekening' => Rekening::where('tampil_invoice', true)->where(fn ($q) => $q->whereNull('id_lokasi')->orWhere('id_lokasi', $pelanggan->id_lokasi))
                ->orderBy('nama_rekening')->get()->map->only(['nama_rekening', 'no_rekening', 'atas_nama']),
        ]);
    }

    public function pembayaran(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->pembayaran()->orderByDesc('periode')->take(self::MAKS_RIWAYAT)->get()
                ->map(fn (Pembayaran $p) => [
                    ...$p->only(['id', 'no_invoice', 'periode', 'nama_paket', 'harga', 'diskon', 'jml_bayar', 'metode']),
                    'periode_pembayaran' => $p->periodePembayaran(),
                    'paid_at' => $p->paid_at?->toIso8601String(),
                ]),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar']);
    }

    /** @return array<string, mixed> */
    private function profilData(Pelanggan $pelanggan): array
    {
        $pelanggan->loadMissing(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi']);

        return [
            ...$pelanggan->only(['id_pelanggan', 'nama', 'jns_tagihan', 'tgl_jatuh_tempo', 'status_langganan']),
            'paket' => $pelanggan->paket ? ['nama' => $pelanggan->paket->nama_paket, 'harga' => $pelanggan->paket->harga] : null,
            'cabang' => $pelanggan->lokasi?->only(['nama_lokasi', 'alamat_lokasi', 'tlp_lokasi']),
        ];
    }
}
