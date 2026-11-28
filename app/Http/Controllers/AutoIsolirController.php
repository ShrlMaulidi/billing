<?php

namespace App\Http\Controllers;

use App\Models\Pengaturan;
use App\Services\Mikrotik\AutoIsolir;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kelola Data › Auto Isolir: nyala/mati, isolir Hari H atau H+1 dari jatuh tempo, jam isolir (WIB), hasil jalan terakhir,
 * dan pratinjau pelanggan yang akan diisolir pada jalan berikutnya. Prosesnya di AutoIsolir (perintah isolir:otomatis).
 */
class AutoIsolirController extends Controller
{
    /** Penjadwal dianggap mati bila perintah isolir:otomatis tidak berjalan selama ini (cron belum dipasang / berhenti). */
    private const DETAK_MAKS_MENIT = 5;

    public function index(AutoIsolir $autoIsolir): Response
    {
        $pengaturan = AutoIsolir::pengaturan();
        $sekarang = AutoIsolir::sekarang();
        $berikutnya = AutoIsolir::berikutnya($sekarang);
        // Pratinjau memakai tanggal jalan berikutnya; saat nonaktif, seolah berjalan hari ini
        $tanggal = $berikutnya ?? $sekarang;
        $detak = Cache::get(AutoIsolir::KUNCI_DETAK);

        return Inertia::render('KelolaData/AutoIsolir', [
            'pengaturan' => collect($pengaturan)->only(['aktif', 'hari', 'jam']),
            'hasil' => $pengaturan['hasil'],
            'berikutnya' => $berikutnya?->toIso8601String(),
            'tanggalPratinjau' => $tanggal->toDateString(),
            'penjadwalAktif' => $detak !== null && Carbon::parse($detak)->gt(now()->subMinutes(self::DETAK_MAKS_MENIT)),
            'batas' => AutoIsolir::BATAS_PER_JALAN,
            'pratinjau' => fn () => $autoIsolir->sasaran($tanggal, $pengaturan['hari'])->map(fn (array $s) => [
                'id' => $s['pelanggan']->id,
                'id_pelanggan' => $s['pelanggan']->id_pelanggan,
                'nama' => $s['pelanggan']->nama,
                'cabang' => $s['pelanggan']->lokasi?->nama_lokasi,
                'paket' => $s['pelanggan']->paket?->nama_paket,
                'periode' => $s['tagihan']['periode_pembayaran'],
                'nominal' => $s['tagihan']['nominal'],
                'jatuh_tempo' => $s['tagihan']['jatuh_tempo'],
            ])->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'aktif' => ['required', 'boolean'],
            'hari' => ['required', 'integer', 'in:0,1'],
            'jam' => ['required', 'date_format:H:i'],
        ], $this->pesanValidasi(), ['aktif' => 'Status', 'hari' => 'Waktu isolir', 'jam' => 'Jam isolir']);

        $lama = AutoIsolir::pengaturan();
        $baru = ['aktif' => (bool) $data['aktif'], 'hari' => (int) $data['hari'], 'jam' => $data['jam']];
        Pengaturan::simpanSemua([
            'auto_isolir_aktif' => $baru['aktif'] ? '1' : '0',
            'auto_isolir_hari' => (string) $baru['hari'],
            'auto_isolir_jam' => $baru['jam'],
        ]);

        $label = fn (string $kunci, mixed $nilai) => match ($kunci) {
            'aktif' => $nilai ? 'Aktif' : 'Nonaktif',
            'hari' => $nilai ? 'H+1 jatuh tempo' : 'Hari H jatuh tempo',
            default => "{$nilai} WIB",
        };
        $perubahan = collect($baru)->filter(fn ($nilai, $kunci) => $lama[$kunci] !== $nilai)
            ->map(fn ($nilai, $kunci) => ['dari' => $label($kunci, $lama[$kunci]), 'ke' => $label($kunci, $nilai)])->all();
        if ($perubahan) {
            Audit::catat('Auto Isolir', 'update', 'Mengubah pengaturan auto isolir', null, $perubahan);
        }

        return response()->json(['message' => $baru['aktif']
            ? 'Auto isolir aktif: '.($baru['hari'] ? 'H+1' : 'Hari H')." jatuh tempo, jam {$baru['jam']} WIB"
            : 'Auto isolir dinonaktifkan']);
    }
}
