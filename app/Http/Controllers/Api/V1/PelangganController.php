<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PelangganResource;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Daftar, detail, dan tagihan pelanggan untuk aplikasi staf. Tambah/ubah/hapus, On/Off, dan pembayaran memakai
 * controller web yang sama (lihat routes/api.php), sehingga validasi & aturannya tidak bercabang.
 */
class PelangganController extends Controller
{
    /** Filter status → permission menu web yang sama. */
    private const IZIN_STATUS = [
        'semua' => 'pelanggan.data.view',
        'on' => 'pelanggan.on.view',
        'off' => 'pelanggan.off.view',
        'lunas' => 'pelanggan.lunas.view',
        'belum_lunas' => 'pelanggan.belum_lunas.view',
        'berhenti' => 'pelanggan.berhenti.view',
        'prorate' => 'pelanggan.prorate.view',
    ];

    private const MAKS_PER_HALAMAN = 100;

    /**
     * status (default semua): sama dengan tab halaman Pelanggan web; lunas/belum lunas menurut bulan tagihan filter.
     * cari: nama, ID pelanggan, nomor HP, atau username.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $input = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::IZIN_STATUS))],
            'cari' => ['nullable', 'string', 'max:100'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAKS_PER_HALAMAN],
        ], $this->pesanValidasi());
        $status = $input['status'] ?? 'semua';
        abort_unless($request->user()->can(self::IZIN_STATUS[$status]), 403, 'Anda tidak memiliki akses ke fitur ini');

        $f = $this->filters($request);
        $periode = $this->periode($f);
        $cari = $input['cari'] ?? null;

        $pelanggan = Pelanggan::query()
            ->with($this->relasi())
            ->when($f['cabang'], fn ($q) => $q->where('id_lokasi', $f['cabang']))
            ->when($cari, fn ($q) => $q->where(fn ($q) => $q->where('nama', 'like', "%$cari%")->orWhere('id_pelanggan', 'like', "%$cari%")
                ->orWhere('tlp', 'like', "%$cari%")->orWhere('username_pppoe', 'like', "%$cari%")))
            ->when($status === 'semua', fn ($q) => $q->where('status', '!=', 'berhenti'))
            ->when(in_array($status, ['on', 'off'], true), fn ($q) => $q->where('status', 'aktif')->where('status_langganan', $status))
            ->when($status === 'berhenti', fn ($q) => $q->where('status', 'berhenti'))
            ->when($status === 'lunas', fn ($q) => $q->lunas($periode))
            ->when($status === 'belum_lunas', fn ($q) => $q->belumLunas($periode))
            // Syarat "sudah muncul & belum dibayar" dicek di PHP lewat prorateBelumDibayar(), sama dengan halaman web
            ->when($status === 'prorate', fn ($q) => $q->whereKey($this->idProrateBelumDibayar($f['cabang'])))
            ->orderBy('nama')
            ->paginate($input['per_page'] ?? 25)
            ->withQueryString();

        return PelangganResource::collection($pelanggan);
    }

    public function show(Request $request, Pelanggan $pelanggan): PelangganResource
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);

        return new PelangganResource($pelanggan->load($this->relasi()));
    }

    /** Invoice yang muncul di bulan tagihan (bulan & tahun, default bulan berjalan), sama dengan halaman tagihan web. */
    public function tagihan(Request $request, Pelanggan $pelanggan): JsonResponse
    {
        $this->pastikanCabang($request, $pelanggan->id_lokasi);
        $f = $this->filters($request);
        abort_unless(in_array($f['tahun'], Pembayaran::tahunBayar(), true) && $f['bulan'] >= 1 && $f['bulan'] <= 12, 404);
        $pelanggan->load(['paket:id,nama_paket,harga', 'lokasi:id,nama_lokasi,alamat_lokasi,tlp_lokasi']);

        return response()->json(['data' => $pelanggan->invoiceLengkap($this->periode($f))]);
    }

    /** @return array<int|string, mixed> */
    private function relasi(): array
    {
        return [
            'router:id,nama_router', 'paket:id,nama_paket,harga,profile_mikrotik', 'lokasi:id,nama_lokasi', 'area:id,nama_area',
            'pembayaran:id,pelanggan_id,periode',
        ];
    }

    /** @return list<int> */
    private function idProrateBelumDibayar(?int $idLokasi): array
    {
        return Pelanggan::where('status', 'aktif')->where('skema_tagihan', 'prorate')->whereNotNull('tgl_registrasi')
            ->whereHas('paket', fn ($p) => $p->where('harga', '>', 0))
            ->when($idLokasi, fn ($q) => $q->where('id_lokasi', $idLokasi))
            ->with('pembayaran:id,pelanggan_id,periode')
            ->get(['id', 'status', 'jns_tagihan', 'skema_tagihan', 'tgl_registrasi'])
            ->filter->prorateBelumDibayar()->modelKeys();
    }
}
