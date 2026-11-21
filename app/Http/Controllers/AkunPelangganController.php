<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use App\Models\User;
use App\Services\AkunPelanggan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu User › Akun Pelanggan: akun login pelanggan yang dibuat otomatis dari Data Pelanggan (terpisah dari Manajemen User).
 * User cabang hanya melihat & mengelola akun yang dipakai pelanggan cabangnya.
 */
class AkunPelangganController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $rows = User::role(AkunPelanggan::ROLE)
            ->with(['lokasi:id,nama_lokasi', 'pelanggan' => fn ($q) => $q->select(['id', 'user_id', 'id_pelanggan', 'nama', 'status', 'id_lokasi'])->orderBy('nama')])
            // Akun tanpa pelanggan (nomor sudah diganti) mengikuti cabang terakhirnya
            ->when($f['cabang'], fn ($q, $cabang) => $q->where(fn ($q) => $q
                ->whereHas('pelanggan', fn ($p) => $p->where('id_lokasi', $cabang))
                ->orWhere(fn ($q) => $q->whereDoesntHave('pelanggan')->where('id_lokasi', $cabang))))
            ->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->name,
                'username' => $u->username,
                'pelanggan' => $u->pelanggan->map(fn (Pelanggan $p) => $p->only(['id_pelanggan', 'nama', 'status']))->values(),
                'cabang' => $u->lokasi?->nama_lokasi ?? '-',
                'status' => $u->status,
                'last_login_at' => $u->last_login_at?->toDateTimeString(),
                'last_login_ip' => $u->last_login_ip,
            ]);

        return Inertia::render('User/AkunPelanggan', ['rows' => $rows, 'filters' => $f]);
    }

    /** Kembalikan password ke nomor HP; pelanggan keluar dari semua sesinya. */
    public function resetPassword(Request $request, User $user, AkunPelanggan $akunPelanggan): JsonResponse
    {
        $this->pastikanBolehKelola($request, $user);
        $akunPelanggan->resetPassword($user);

        return response()->json(['message' => "Password akun {$user->name} dikembalikan ke nomor HP ({$user->username})"]);
    }

    /** Tombol Aktifkan / Nonaktifkan; akun nonaktif langsung keluar dari semua sesinya. */
    public function status(Request $request, User $user): JsonResponse
    {
        $this->pastikanBolehKelola($request, $user);
        $user->update(['status' => $user->status === 'aktif' ? 'nonaktif' : 'aktif']);
        if ($user->status !== 'aktif') {
            $user->putusSesi();
        }

        return response()->json(['message' => "Akun {$user->name} ".($user->status === 'aktif' ? 'diaktifkan' : 'dinonaktifkan')]);
    }

    /** Hanya akun pelanggan; user cabang hanya akun yang dipakai pelanggan cabangnya (selain itu 404). */
    private function pastikanBolehKelola(Request $request, User $user): void
    {
        abort_unless($user->isPelanggan(), 404);
        $cabang = $request->user()->id_lokasi;
        if ($cabang !== null) {
            $milikCabang = $user->pelanggan()->exists() ? $user->pelanggan()->where('id_lokasi', $cabang)->exists() : $user->id_lokasi === $cabang;
            abort_unless($milikCabang, 404);
        }
    }
}
