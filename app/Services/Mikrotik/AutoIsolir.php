<?php

namespace App\Services\Mikrotik;

use App\Models\IsolirLog;
use App\Models\Pelanggan;
use App\Models\Pengaturan;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Isolir otomatis pelanggan yang tagihannya lewat jatuh tempo (Kelola Data › Auto Isolir), dijalankan penjadwal Laravel
 * sekali sehari mulai jam yang diatur (WIB). Pelanggan diisolir bila:
 * - aktif, internet masih On, berpaket berbayar, punya router & tanggal jatuh tempo;
 * - tagihan bulan berjalan belum dibayar dan jatuh temponya (Hari H) atau sehari setelahnya (H+1) sudah tiba.
 *   Tunggakan bulan-bulan sebelumnya tidak membuat pelanggan diisolir.
 * Pengaman: tagihan bulan registrasi yang jatuh temponya sebelum tanggal registrasi tidak dihitung; pelanggan yang
 * dinyalakan kembali oleh staf setelah tagihannya lewat jatuh tempo tidak diisolir ulang untuk tagihan yang sama;
 * paling banyak BATAS_PER_JALAN pelanggan sekali jalan; router yang tidak terjangkau dilewati sisa pelanggannya.
 */
class AutoIsolir
{
    /** Jam isolir & tanggal jatuh tempo memakai waktu Indonesia Barat, tidak bergantung zona waktu server. */
    public const ZONA = 'Asia/Jakarta';

    public const BATAS_PER_JALAN = 300;

    public const PELAKU = 'Sistem (auto isolir)';

    public const PELAKU_AKTIF = 'Sistem (aktif otomatis setelah bayar)';

    /** Detak penjadwal: diperbarui setiap kali perintah isolir:otomatis berjalan (tiap menit lewat cron). */
    public const KUNCI_DETAK = 'auto-isolir:detak';

    /** @var array<string, ?string> */
    public const PENGATURAN = [
        'auto_isolir_aktif' => '0',
        // 0 = Hari H (tanggal jatuh tempo), 1 = H+1
        'auto_isolir_hari' => '1',
        'auto_isolir_jam' => '10:00',
        // Tanggal (WIB) terakhir dijalankan, agar hanya sekali sehari
        'auto_isolir_terakhir' => null,
        // Ringkasan hasil jalan terakhir (JSON)
        'auto_isolir_hasil' => null,
    ];

    public function __construct(private IsolirService $isolir) {}

    /** @return array{aktif: bool, hari: int, jam: string, terakhir: ?string, hasil: ?array<string, mixed>} */
    public static function pengaturan(): array
    {
        $p = Pengaturan::ambil(self::PENGATURAN);

        return [
            'aktif' => $p['auto_isolir_aktif'] === '1',
            'hari' => (int) $p['auto_isolir_hari'] === 0 ? 0 : 1,
            'jam' => $p['auto_isolir_jam'] ?: '10:00',
            'terakhir' => $p['auto_isolir_terakhir'],
            'hasil' => $p['auto_isolir_hasil'] ? json_decode($p['auto_isolir_hasil'], true) : null,
        ];
    }

    public static function sekarang(): Carbon
    {
        return now()->setTimezone(self::ZONA);
    }

    /** Aktif, jam isolir hari ini sudah lewat, dan belum dijalankan hari ini. */
    public function waktunyaJalan(?Carbon $sekarang = null): bool
    {
        $sekarang ??= self::sekarang();
        $p = self::pengaturan();

        return $p['aktif'] && $sekarang->format('H:i') >= $p['jam'] && $p['terakhir'] !== $sekarang->toDateString();
    }

    /** Waktu jalan berikutnya (WIB), null bila auto isolir nonaktif. */
    public static function berikutnya(?Carbon $sekarang = null): ?Carbon
    {
        $sekarang ??= self::sekarang();
        $p = self::pengaturan();
        if (! $p['aktif']) {
            return null;
        }
        [$jam, $menit] = array_map('intval', explode(':', $p['jam']));
        $hariIni = $sekarang->copy()->setTime($jam, $menit);

        return $p['terakhir'] === $sekarang->toDateString() ? $hariIni->addDay() : $hariIni->max($sekarang);
    }

    /**
     * Pelanggan yang diisolir bila auto isolir berjalan pada $tanggal (WIB), beserta tagihan yang membuatnya diisolir.
     * Hanya tagihan bulan berjalan yang dihitung (tunggakan bulan sebelumnya tidak), sama dengan Pelanggan Belum Lunas.
     * Tagihan bulan lalu ikut diperiksa hanya bila waktu isolirnya jatuh di bulan ini (jatuh tempo akhir bulan + H+1).
     *
     * @return Collection<int, array{pelanggan: Pelanggan, tagihan: array<string, mixed>, isolir_mulai: string}>
     */
    public function sasaran(Carbon $tanggal, int $hari): Collection
    {
        $hasil = collect();
        foreach (self::bulanDiperiksa($tanggal) as $bulanTagihan) {
            Pelanggan::belumLunas($bulanTagihan)
                ->with(['paket:id,harga,nama_paket', 'router', 'lokasi:id,nama_lokasi'])
                ->where('status_langganan', 'on')->whereNotNull('tgl_jatuh_tempo')->whereNotNull('router_id')
                ->whereNotIn('id', $hasil->keys())
                ->get()
                ->each(function (Pelanggan $p) use ($bulanTagihan, $hari, $tanggal, $hasil) {
                    if ($lewat = self::lewatTempo($p, $bulanTagihan, $hari, $tanggal)) {
                        $hasil->put($p->id, ['pelanggan' => $p, ...$lewat]);
                    }
                });
        }
        $hasil = $hasil->sortBy(fn (array $s) => $s['pelanggan']->nama)->values();

        // Staf menyalakan kembali setelah tagihan lewat jatuh tempo (mis. janji bayar): hormati sampai tagihan berikutnya
        $dinyalakan = IsolirLog::whereIn('pelanggan_id', $hasil->pluck('pelanggan.id'))->where('aksi', 'aktifkan')->where('berhasil', true)
            ->selectRaw('pelanggan_id, MAX(created_at) as terakhir')->groupBy('pelanggan_id')->pluck('terakhir', 'pelanggan_id');

        return $hasil->reject(function (array $s) use ($dinyalakan) {
            $terakhir = $dinyalakan->get($s['pelanggan']->id);

            return $terakhir && Carbon::parse($terakhir)->setTimezone(self::ZONA)->toDateString() >= $s['isolir_mulai'];
        })->values();
    }

    /**
     * Masih punya tagihan yang membuatnya diisolir (aturan sama dengan sasaran()): dipakai sebelum menyalakan kembali
     * internet setelah pembayaran. Memakai pengaturan Hari H / H+1 walau auto isolir sedang nonaktif.
     */
    public function masihLewatTempo(Pelanggan $pelanggan, ?Carbon $tanggal = null): bool
    {
        $tanggal ??= self::sekarang();
        $hari = self::pengaturan()['hari'];

        return collect(self::bulanDiperiksa($tanggal))->contains(fn (string $bulanTagihan) => Pelanggan::belumLunas($bulanTagihan)->whereKey($pelanggan->id)->exists()
            && self::lewatTempo($pelanggan, $bulanTagihan, $hari, $tanggal) !== null);
    }

    /**
     * Setelah pembayaran tercatat (input kasir, pembayaran massal, payment gateway): nyalakan kembali internet pelanggan
     * yang Off. Dijalankan setelah respons terkirim agar koneksi router tidak memperlambat atau menggagalkan pembayaran;
     * beberapa periode yang dibayar sekaligus hanya menyalakan sekali.
     */
    public static function jadwalkanAktifSetelahBayar(Pelanggan $pelanggan): void
    {
        if ($pelanggan->status_langganan !== 'off') {
            return;
        }
        defer(fn () => app(self::class)->aktifkanSetelahBayar($pelanggan->id), "aktif-setelah-bayar-{$pelanggan->id}");
    }

    /** @return bool true bila internet dinyalakan kembali */
    public function aktifkanSetelahBayar(int $pelangganId): bool
    {
        $pelanggan = Pelanggan::with(['paket', 'router'])->find($pelangganId);
        if (! $pelanggan || $pelanggan->status !== 'aktif' || $pelanggan->status_langganan !== 'off' || ! $pelanggan->router_id || $this->masihLewatTempo($pelanggan)) {
            return false;
        }
        try {
            Audit::diam(fn () => $this->isolir->aktifkan($pelanggan));
            Audit::catat('Pelanggan', 'aktifkan', "Menyalakan kembali internet pelanggan {$pelanggan->labelAudit()} otomatis setelah pembayaran",
                $pelanggan, ['status_langganan' => ['dari' => 'off', 'ke' => 'on']], self::PELAKU_AKTIF);

            return true;
        } catch (Throwable $e) {
            Audit::catat('Pelanggan', 'aktifkan', "Gagal menyalakan kembali internet pelanggan {$pelanggan->labelAudit()} setelah pembayaran ({$e->getMessage()}), nyalakan manual dari menu Pelanggan Off",
                $pelanggan, null, self::PELAKU_AKTIF);

            return false;
        }
    }

    /**
     * Bulan tagihan yang diperiksa: bulan berjalan, dan bulan lalu (hanya bila waktu isolirnya jatuh di bulan ini).
     *
     * @return list<string> Y-m-01
     */
    private static function bulanDiperiksa(Carbon $tanggal): array
    {
        $awal = $tanggal->copy()->startOfMonth();

        return [$awal->format('Y-m-d'), $awal->copy()->subMonth()->format('Y-m-d')];
    }

    /**
     * Tagihan bulan itu sudah lewat batas isolir pada $tanggal (dan batasnya jatuh di bulan $tanggal). Pelanggan yang daftar
     * setelah jatuh tempo bulan itu belum dianggap lewat jatuh tempo. Tidak memeriksa sudah dibayar atau belum.
     *
     * @return array{tagihan: array<string, mixed>, isolir_mulai: string}|null
     */
    private static function lewatTempo(Pelanggan $pelanggan, string $bulanTagihan, int $hari, Carbon $tanggal): ?array
    {
        $tagihan = $pelanggan->invoice($bulanTagihan);
        $registrasi = $pelanggan->tgl_registrasi?->toDateString();
        if (! $tagihan['jatuh_tempo'] || ($registrasi && $tagihan['jatuh_tempo'] < $registrasi)) {
            return null;
        }
        $mulai = Carbon::parse($tagihan['jatuh_tempo'])->addDays($hari)->toDateString();

        return $mulai >= $tanggal->copy()->startOfMonth()->toDateString() && $mulai <= $tanggal->toDateString()
            ? ['tagihan' => $tagihan, 'isolir_mulai' => $mulai]
            : null;
    }

    /**
     * Jalankan isolir untuk sasaran hari ini dan simpan ringkasannya. Hari ini langsung ditandai sudah berjalan
     * (sebelum mengisolir) agar tidak terulang walau proses terhenti di tengah.
     *
     * @return array{waktu: string, sasaran: int, diisolir: int, gagal: int, ditunda: int, rincian_gagal: list<array{nama: string, pesan: string}>}|null null bila sedang berjalan
     */
    public function jalankan(): ?array
    {
        $kunci = Cache::lock('auto-isolir:jalan', 3600);
        if (! $kunci->get()) {
            return null;
        }
        try {
            $sekarang = self::sekarang();
            $p = self::pengaturan();
            Pengaturan::simpanSemua(['auto_isolir_terakhir' => $sekarang->toDateString()]);

            $semua = $this->sasaran($sekarang, $p['hari']);
            $hasil = ['waktu' => $sekarang->toIso8601String(), 'sasaran' => $semua->count(), 'diisolir' => 0, 'gagal' => 0,
                'ditunda' => max(0, $semua->count() - self::BATAS_PER_JALAN), 'rincian_gagal' => []];
            $routerMati = [];

            Audit::sebagai(self::PELAKU, function () use ($semua, &$hasil, &$routerMati) {
                foreach ($semua->take(self::BATAS_PER_JALAN) as ['pelanggan' => $pelanggan, 'tagihan' => $tagihan]) {
                    $pesan = $routerMati[$pelanggan->router_id] ?? null;
                    if ($pesan === null) {
                        try {
                            Audit::diam(fn () => $this->isolir->isolir($pelanggan));
                            Audit::catat('Pelanggan', 'isolir', "Auto isolir: mematikan internet pelanggan {$pelanggan->labelAudit()} (tagihan jatuh tempo {$tagihan['jatuh_tempo']} belum dibayar)",
                                $pelanggan, ['status_langganan' => ['dari' => 'on', 'ke' => 'off']]);
                            $hasil['diisolir']++;

                            continue;
                        } catch (RouterOsException $e) {
                            $pesan = $e->getMessage();
                            // Router tidak terjangkau / login gagal: pelanggan lain di router itu tidak dicoba (menghindari timeout beruntun)
                            if ($e->jenis !== 'perintah') {
                                $routerMati[$pelanggan->router_id] = $pesan;
                            }
                        }
                    }
                    $hasil['gagal']++;
                    if (count($hasil['rincian_gagal']) < 50) {
                        $hasil['rincian_gagal'][] = ['nama' => $pelanggan->nama, 'pesan' => $pesan];
                    }
                }
                Audit::catat('Auto Isolir', 'isolir', "Auto isolir berjalan: {$hasil['diisolir']} pelanggan diisolir, {$hasil['gagal']} gagal".($hasil['ditunda'] ? ", {$hasil['ditunda']} ditunda ke besok" : ''));
            });

            Pengaturan::simpanSemua(['auto_isolir_hasil' => json_encode($hasil)]);

            return $hasil;
        } finally {
            $kunci->release();
        }
    }
}
