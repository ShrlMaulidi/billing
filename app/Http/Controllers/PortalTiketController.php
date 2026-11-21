<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\Tiket;
use App\Models\TiketPesan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Tiket di dashboard pelanggan: buat tiket keluhan/permintaan, lihat status, dan balas petugas.
 * Pelanggan hanya bisa membuka tiket miliknya (selain itu 404); tiket selesai tidak bisa dibalas lagi.
 */
class PortalTiketController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Portal/Tiket', [
            'rows' => Tiket::with(['pelanggan:id,id_pelanggan,nama', 'lokasi:id,nama_lokasi'])->where('user_id', $user->id)
                ->latest('id')->get()->map(fn (Tiket $t) => $t->toBaris()),
            'langganan' => Pelanggan::where('user_id', $user->id)->where('status', 'aktif')->orderBy('nama')->get(['id', 'id_pelanggan', 'nama'])
                ->map(fn (Pelanggan $p) => ['value' => $p->id, 'label' => trim("{$p->id_pelanggan} · {$p->nama}", ' ·')]),
            'kategori' => collect(Tiket::KATEGORI)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'pelanggan_id' => ['required', 'integer', Rule::exists('pelanggan', 'id')->where('user_id', $user->id)],
            'kategori' => ['required', Rule::in(array_keys(Tiket::KATEGORI))],
            'judul' => ['required', 'string', 'max:150'],
            'pesan' => ['required', 'string', 'max:2000'],
        ], $this->pesanValidasi(), ['pelanggan_id' => 'Langganan', 'kategori' => 'Kategori', 'judul' => 'Judul', 'pesan' => 'Isi keluhan']);

        $tiket = DB::transaction(function () use ($data, $user) {
            // Nomor tiket memakai id, jadi diisi setelah tersimpan; tanpa event agar log audit tidak mencatat nomor sementara
            $tiket = Tiket::withoutEvents(fn () => Tiket::create([
                'no_tiket' => 'TKT-'.bin2hex(random_bytes(8)), 'user_id' => $user->id, 'pelanggan_id' => $data['pelanggan_id'],
                'id_lokasi' => Pelanggan::whereKey($data['pelanggan_id'])->value('id_lokasi'),
                'kategori' => $data['kategori'], 'judul' => $data['judul'],
            ]));
            $tiket->forceFill(['no_tiket' => Tiket::nomorUntuk($tiket->id)])->saveQuietly();
            $tiket->pesan()->create(['user_id' => $user->id, 'dari_pelanggan' => true, 'pesan' => $data['pesan']]);

            return $tiket;
        });

        return response()->json(['message' => "Tiket {$tiket->no_tiket} terkirim. Petugas kami akan segera menanggapi.", 'id' => $tiket->id]);
    }

    public function show(Request $request, Tiket $tiket): Response
    {
        $this->pastikanMilik($request, $tiket);
        $tiket->load(['pelanggan:id,id_pelanggan,nama', 'lokasi:id,nama_lokasi', 'pesan.user:id,name']);

        return Inertia::render('Portal/TiketDetail', [
            'tiket' => $tiket->toBaris(),
            'pesan' => $tiket->pesan->map(fn (TiketPesan $p) => $p->toBaris()),
        ]);
    }

    public function balas(Request $request, Tiket $tiket): JsonResponse
    {
        $this->pastikanMilik($request, $tiket);
        abort_if($tiket->status === 'selesai', 422, 'Tiket sudah selesai. Buat tiket baru bila masih ada kendala.');
        $data = $request->validate(['pesan' => ['required', 'string', 'max:2000']], $this->pesanValidasi(), ['pesan' => 'Pesan']);

        $tiket->pesan()->create(['user_id' => $request->user()->id, 'dari_pelanggan' => true, 'pesan' => $data['pesan']]);
        $tiket->touch();

        return response()->json(['message' => 'Pesan terkirim']);
    }

    /** Tiket pelanggan lain dijawab 404 agar keberadaannya tidak terbaca. */
    private function pastikanMilik(Request $request, Tiket $tiket): void
    {
        abort_unless($tiket->user_id === $request->user()->id, 404);
    }
}
