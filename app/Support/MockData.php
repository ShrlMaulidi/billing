<?php

namespace App\Support;

use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Data dummy untuk tahap UI. Struktur mengikuti spesifikasi §4 sehingga
 * nanti cukup diganti dengan query Eloquent tanpa mengubah halaman React.
 */
class MockData
{
    protected static array $cache = [];

    protected static ?Generator $faker = null;

    protected static function once(string $key, callable $fn): mixed
    {
        return self::$cache[$key] ??= $fn();
    }

    protected static function faker(): Generator
    {
        if (! self::$faker) {
            self::$faker = Factory::create('id_ID');
            self::$faker->seed(2026);
        }

        return self::$faker;
    }

    public static function today(): Carbon
    {
        return Carbon::today();
    }

    /** 12 periode terakhir (Y-m-01), terlama → terbaru. */
    public static function periods(): array
    {
        return self::once('periods', function () {
            $start = self::today()->startOfMonth()->subMonths(11);

            return collect(range(0, 11))->map(fn ($i) => $start->copy()->addMonths($i)->format('Y-m-01'))->all();
        });
    }

    public static function lokasi(): Collection
    {
        return self::once('lokasi', function () {
            $f = self::faker();
            $kota = ['Bandung (Pusat)', 'Cimahi', 'Garut', 'Tasikmalaya', 'Sumedang', 'Subang', 'Purwakarta', 'Cianjur', 'Sukabumi', 'Majalengka'];

            return collect($kota)->map(fn ($k, $i) => [
                'id' => $i + 1,
                'kode_lokasi' => sprintf('C%03d', $i + 1),
                'nama_lokasi' => "MSS $k",
                'alamat_lokasi' => $f->streetAddress().', '.explode(' ', $k)[0],
                'tlp_lokasi' => '022'.$f->numerify('#######'),
                'tlp_owner' => '08'.$f->numerify('##########'),
                'id_mitra' => $i + 3,
                'nama_mitra' => $i === 0 ? 'Pusat' : $f->name(),
                'metode_bagi_hasil' => $i % 3 === 2 ? 'nominal' : 'persentase',
                'nilai_bagi_hasil' => $i % 3 === 2 ? $f->randomElement([1500000, 2000000, 2500000]) : $f->randomElement([20, 25, 30]),
                'group_wa' => '1203630'.$f->numerify('########').'@g.us',
                'terima_cash' => $i % 4 !== 3,
                'status' => $i === 9 ? 'tidak_aktif' : 'aktif',
            ]);
        });
    }

    public static function lokasiOptions(): array
    {
        return self::lokasi()->map(fn ($l) => ['value' => $l['id'], 'label' => $l['nama_lokasi']])->values()->all();
    }

    protected static function namaLokasi(?int $id): string
    {
        return $id ? (self::lokasi()->firstWhere('id', $id)['nama_lokasi'] ?? '-') : 'Semua Cabang';
    }

    public static function pajak(): Collection
    {
        return self::once('pajak', function () {
            $rows = collect([[
                'id' => 1, 'id_lokasi' => null, 'cabang' => 'Default Global', 'ppn_persen' => 11, 'pph_persen' => 2,
                'bhp_persen' => 0.5, 'uso_persen' => 1.25, 'admin_fee_tipe' => 'persentase', 'admin_fee' => 5, 'berlaku_sejak' => '2025-01-01',
            ]]);

            return $rows->merge(self::lokasi()->map(fn ($l, $i) => [
                'id' => $i + 2,
                'id_lokasi' => $l['id'],
                'cabang' => $l['nama_lokasi'],
                'ppn_persen' => 11,
                'pph_persen' => 2,
                'bhp_persen' => 0.5,
                'uso_persen' => 1.25,
                'admin_fee_tipe' => $i % 4 === 3 ? 'nominal' : 'persentase',
                'admin_fee' => $i % 4 === 3 ? 2500 : 5,
                'berlaku_sejak' => $i % 2 ? '2026-01-01' : '2025-07-01',
            ]));
        });
    }

    public static function pajakLokasi(int $idLokasi): array
    {
        return self::pajak()->firstWhere('id_lokasi', $idLokasi) ?? self::pajak()->first();
    }

    public static function paket(): Collection
    {
        return self::once('paket', function () {
            $tpl = [
                ['Home 10 Mbps', 'Up to 10 Mbps', 150000, 'bulanan', 'pppoe'],
                ['Home 20 Mbps', 'Up to 20 Mbps', 200000, 'bulanan', 'pppoe'],
                ['Ultra 30 Mbps', 'Up to 30 Mbps', 275000, 'bulanan', 'pppoe'],
                ['Ultra 50 Mbps', 'Up to 50 Mbps', 350000, 'bulanan', 'pppoe'],
                ['Bisnis 100 Mbps', 'Dedicated 100 Mbps', 750000, 'bulanan', 'pppoe'],
                ['Free Sosial', 'Paket gratis fasilitas umum', 0, 'bulanan', 'pppoe'],
                ['Hotspot Harian', 'Voucher 24 jam', 5000, 'non_bulanan', 'hotspot'],
                ['Hotspot Mingguan', 'Voucher 7 hari', 25000, 'non_bulanan', 'hotspot'],
                ['Home 15 Mbps Garut', 'Promo khusus cabang Garut', 175000, 'bulanan', 'pppoe'],
                ['Ultra 25 Mbps Cianjur', 'Khusus cabang Cianjur', 250000, 'bulanan', 'pppoe'],
            ];

            return collect($tpl)->map(fn ($t, $i) => [
                'id' => $i + 1,
                'nama_paket' => $t[0],
                'keterangan' => $t[1],
                'harga' => $t[2],
                'jenis' => $t[3],
                'id_lokasi' => $i === 8 ? 3 : ($i === 9 ? 8 : 1),
                'cabang' => self::namaLokasi($i === 8 ? 3 : ($i === 9 ? 8 : 1)),
                'filter_lokasi' => $i >= 8,
                'tampil_registrasi' => ! in_array($i, [5, 7]),
                'id_router' => null,
                'jns_koneksi' => $t[4],
                'profile_mikrotik' => str_replace(' ', '-', strtoupper($t[0])),
                'buat_profile' => true,
                'buat_pool' => $i < 5,
                'nama_pool' => $i < 5 ? 'pool-'.($i + 1) : '',
                'local_address' => $i < 5 ? '10.10.'.($i + 1).'.1' : '',
                'remote_address' => $i < 5 ? 'pool-'.($i + 1) : '',
            ]);
        });
    }

    public static function router(): Collection
    {
        return self::once('router', function () {
            $f = self::faker();
            $rows = collect();
            foreach (self::lokasi() as $l) {
                foreach (range(1, $l['id'] <= 4 ? 2 : 1) as $n) {
                    $id = $rows->count() + 1;
                    $rows->push([
                        'id' => $id,
                        'kode_router' => sprintf('RTR-%03d', $id),
                        'nama_router' => 'CCR-'.explode(' ', $l['nama_lokasi'])[1].($n > 1 ? "-$n" : ''),
                        'host' => $id % 3 ? $f->localIpv4() : 'rtr'.$id.'.mss.net.id',
                        'port_api' => 8728,
                        'username' => 'api-billing',
                        'id_lokasi' => $l['id'],
                        'cabang' => $l['nama_lokasi'],
                        'status_koneksi' => $id % 6 === 0 ? 'offline' : 'online',
                        'last_checked_at' => self::today()->copy()->setTime(13, $id * 3)->toDateTimeString(),
                    ]);
                }
            }

            return $rows;
        });
    }

    public static function rekening(): Collection
    {
        return self::once('rekening', function () {
            $f = self::faker();
            $rows = collect([['id' => 1, 'nama_rekening' => 'Cash / Tunai', 'atas_nama' => '-', 'no_rekening' => '-', 'id_lokasi' => 1, 'cabang' => 'Semua Cabang', 'tampil_invoice' => false, 'is_cash' => true]]);
            $banks = ['BCA', 'BRI', 'BNI', 'Mandiri', 'DANA', 'BSI'];
            foreach (range(0, 10) as $i) {
                $lok = self::lokasi()[$i % 10];
                $rows->push([
                    'id' => $i + 2,
                    'nama_rekening' => $banks[$i % count($banks)],
                    'atas_nama' => $i < 3 ? 'PT MSS Net Indonesia' : $lok['nama_mitra'],
                    'no_rekening' => $f->numerify(str_repeat('#', 10)),
                    'id_lokasi' => $i < 3 ? 1 : $lok['id'],
                    'cabang' => $i < 3 ? self::namaLokasi(1) : $lok['nama_lokasi'],
                    'tampil_invoice' => $i % 4 !== 3,
                    'is_cash' => false,
                ]);
            }

            return $rows;
        });
    }

    public static function pelanggan(): Collection
    {
        return self::once('pelanggan', function () {
            $f = self::faker();
            $today = self::today();
            $bulanan = self::paket()->where('jenis', 'bulanan')->values();
            $rows = collect();
            foreach (range(1, 300) as $i) {
                $lok = self::lokasi()[$f->biasedNumberBetween(0, 9, fn ($x) => 1 - $x * 0.7)];
                $paket = $f->boolean(4) ? $bulanan->firstWhere('harga', 0) : $f->randomElement($bulanan->where('harga', '>', 0)->where('filter_lokasi', false)->values()->all());
                $routers = self::router()->where('id_lokasi', $lok['id']);
                $reg = $i > 275
                    ? $today->copy()->startOfMonth()->addDays($f->numberBetween(0, max(0, $today->day - 1)))
                    : Carbon::instance($f->dateTimeBetween('-30 months', $today->copy()->startOfMonth()->subDay()))->startOfDay();
                $berhenti = $i <= 275 && $f->boolean(6);
                $rows->push([
                    'id' => $i,
                    'id_pelanggan' => $lok['kode_lokasi'].sprintf('%04d', $i),
                    'nama' => $f->name(),
                    'alamat' => $f->streetAddress(),
                    'tlp' => '08'.$f->numerify('##########'),
                    'email' => $f->boolean(60) ? $f->safeEmail() : null,
                    'id_lokasi' => $lok['id'],
                    'cabang' => $lok['nama_lokasi'],
                    'id_paket' => $paket['id'],
                    'paket' => $paket['nama_paket'],
                    'harga' => $paket['harga'],
                    'id_router' => $routers->first()['id'] ?? null,
                    'tgl_registrasi' => $reg->toDateString(),
                    'tgl_jatuh_tempo' => $f->randomElement([1, 5, 10, 15, 20, 25]),
                    'jns_tagihan' => $f->boolean(70) ? 'prabayar' : 'pascabayar',
                    'status' => $berhenti ? 'berhenti' : 'aktif',
                    'status_langganan' => $berhenti ? 'off' : ($f->boolean(9) ? 'off' : 'on'),
                    'tgl_berhenti' => $berhenti ? $f->dateTimeBetween('-6 months')->format('Y-m-d') : null,
                ]);
            }

            return $rows;
        });
    }

    /** Tagihan & pembayaran 12 bulan terakhir. */
    protected static function billing(): array
    {
        return self::once('billing', function () {
            $f = self::faker();
            $today = self::today();
            $current = $today->format('Y-m-01');
            $tagihan = [];
            $bayar = [];
            $metode = ['cash' => 35, 'transfer' => 25, 'tripay' => 15, 'xendit' => 15, 'pakar_digital' => 10];
            $petugas = ['Admin Pusat', 'Rina Kasir', 'Dodi Teknisi', 'Sari Admin'];
            $rekening = self::rekening()->where('is_cash', false)->values();
            $seq = 0;

            foreach (self::periods() as $periode) {
                $p = Carbon::parse($periode);
                foreach (self::pelanggan() as $pl) {
                    if ($pl['tgl_registrasi'] > $p->copy()->endOfMonth()->toDateString()) {
                        continue;
                    }
                    if ($pl['tgl_berhenti'] && $pl['tgl_berhenti'] < $periode) {
                        continue;
                    }
                    $seq++;
                    $isCurrent = $periode === $current;
                    $lunas = $pl['harga'] == 0 || $f->boolean($isCurrent ? 62 : 94);
                    $pajak = self::pajakLokasi($pl['id_lokasi']);
                    $total = $pl['harga'] + round($pl['harga'] * $pajak['ppn_persen'] / 100);
                    $inv = 'INV'.$p->format('ym').sprintf('%05d', $seq);
                    $tagihan[] = [
                        'no_invoice' => $inv,
                        'id_pelanggan' => $pl['id'],
                        'id_lokasi' => $pl['id_lokasi'],
                        'periode' => $periode,
                        'total' => $total,
                        'status' => $lunas ? 'lunas' : 'belum_lunas',
                    ];
                    if (! $lunas || $total == 0) {
                        continue;
                    }
                    $m = $f->randomElement(array_merge(...array_map(fn ($k, $w) => array_fill(0, $w, $k), array_keys($metode), $metode)));
                    $maxDay = $isCurrent ? $today->day : $p->daysInMonth;
                    $paidAt = $p->copy()->addDays($f->numberBetween(0, $maxDay - 1))->setTime($f->numberBetween(7, 21), $f->numberBetween(0, 59));
                    if ($isCurrent && $f->boolean(7)) {
                        $paidAt = $today->copy()->setTime($f->numberBetween(7, 13), $f->numberBetween(0, 59));
                    }
                    $rek = $m === 'transfer' ? $f->randomElement($rekening->all()) : null;
                    $bayar[] = [
                        'no_invoice' => $inv,
                        'id_pelanggan' => $pl['id_pelanggan'],
                        'nama' => $pl['nama'],
                        'id_lokasi' => $pl['id_lokasi'],
                        'cabang' => $pl['cabang'],
                        'periode' => $periode,
                        'jml_bayar' => $total,
                        'metode' => $m,
                        'id_rekening' => $rek['id'] ?? null,
                        'rekening' => $rek ? $rek['nama_rekening'].' - '.$rek['atas_nama'] : null,
                        'reference_pg' => in_array($m, ['tripay', 'xendit', 'pakar_digital']) ? strtoupper(substr($m, 0, 2)).$f->numerify('##########') : null,
                        'petugas' => in_array($m, ['cash', 'transfer']) ? $f->randomElement($petugas) : 'Sistem ('.ucfirst(str_replace('_', ' ', $m)).')',
                        'paid_at' => $paidAt->toDateTimeString(),
                    ];
                }
            }

            return ['tagihan' => collect($tagihan), 'pembayaran' => collect($bayar)];
        });
    }

    public static function tagihan(): Collection
    {
        return self::billing()['tagihan'];
    }

    public static function pembayaran(): Collection
    {
        return self::billing()['pembayaran'];
    }

    public static function pengeluaran(): Collection
    {
        return self::once('pengeluaran', function () {
            $f = self::faker();
            $rows = collect();
            $wajib = [['Sewa Tempat / POP', 500000], ['Gaji Teknisi', 1500000], ['Bandwidth Upstream', 1250000]];
            $lain = ['Pembelian Kabel FO', 'Bensin Operasional', 'Perbaikan ODP', 'Pembelian ONT', 'Konsumsi Rapat', 'Pulsa & Paket Data'];
            $today = self::today();
            foreach (self::periods() as $periode) {
                $p = Carbon::parse($periode);
                $maxDay = $p->isSameMonth($today) ? $today->day : $p->daysInMonth;
                foreach (self::lokasi() as $l) {
                    $scale = $l['id'] === 1 ? 2.5 : 1;
                    foreach ($wajib as $w) {
                        $rows->push(self::rowPengeluaran($rows->count() + 1, $l, 'wajib_bulanan', $w[0], $w[1] * $scale, $p->copy()->addDays(min(4, $maxDay - 1))->setTime(9, 0), $f));
                    }
                    foreach (range(1, $f->numberBetween(0, 3)) as $_) {
                        $rows->push(self::rowPengeluaran($rows->count() + 1, $l, 'tidak_wajib', $f->randomElement($lain), $f->numberBetween(5, 40) * 10000, $p->copy()->addDays($f->numberBetween(0, $maxDay - 1))->setTime($f->numberBetween(8, 18), 0), $f));
                    }
                }
            }

            return $rows;
        });
    }

    protected static function rowPengeluaran(int $id, array $l, string $tipe, string $jenis, float $nominal, Carbon $waktu, Generator $f): array
    {
        $rek = $f->boolean(50) ? $f->randomElement(self::rekening()->where('is_cash', false)->values()->all()) : null;

        return [
            'id' => $id,
            'id_lokasi' => $l['id'],
            'cabang' => $l['nama_lokasi'],
            'tipe' => $tipe,
            'jenis_pengeluaran' => $jenis,
            'keterangan' => $tipe === 'wajib_bulanan' ? 'Otomatis dari master bulanan' : $f->sentence(4),
            'nominal' => $nominal,
            'id_rekening' => $rek['id'] ?? null,
            'metode' => $rek ? $rek['nama_rekening'] : 'Cash',
            'waktu' => $waktu->toDateTimeString(),
            'bukti' => $f->boolean(70) ? 'bukti-'.$id.'.jpg' : null,
            'created_by' => $f->randomElement(['Admin Pusat', 'Rina Kasir', $l['nama_mitra']]),
        ];
    }

    public static function masterPengeluaran(): Collection
    {
        return self::once('master_pengeluaran', fn () => self::lokasi()->take(5)->flatMap(fn ($l, $i) => [
            ['id' => $i * 3 + 1, 'id_lokasi' => $l['id'], 'cabang' => $l['nama_lokasi'], 'jenis_pengeluaran' => 'Sewa Tempat / POP', 'nominal' => 1500000, 'tgl_generate' => 5, 'aktif' => true],
            ['id' => $i * 3 + 2, 'id_lokasi' => $l['id'], 'cabang' => $l['nama_lokasi'], 'jenis_pengeluaran' => 'Gaji Teknisi', 'nominal' => 3500000, 'tgl_generate' => 1, 'aktif' => true],
            ['id' => $i * 3 + 3, 'id_lokasi' => $l['id'], 'cabang' => $l['nama_lokasi'], 'jenis_pengeluaran' => 'Bandwidth Upstream', 'nominal' => 4000000, 'tgl_generate' => 5, 'aktif' => $i !== 4],
        ])->values());
    }

    /**
     * Rekap dasar per cabang & periode (tanpa data setoran/WD).
     * Rumus mengikuti "Ringkasan Bulan Ini" aplikasi lama:
     *   DPP = Pemasukan / (1 + %PPN) · PPN = Pemasukan − DPP · Admin Fee = DPP × % (atau nominal × transaksi)
     *   Bagi Hasil = (DPP − Admin Fee) × % atau nominal · Omset Reseller = DPP − Admin Fee − Bagi Hasil
     *   PPh = Omset Reseller × %PPh · Total Setor ke MSS = PPN + Admin Fee + Bagi Hasil + PPh
     *   Pendapatan Reseller = Omset Reseller − PPh
     */
    protected static function rekapBase(array $l, string $periode): array
    {
        $key = "rb:{$l['id']}:$periode";

        return self::once($key, function () use ($l, $periode) {
            $endPeriode = Carbon::parse($periode)->endOfMonth()->toDateString();
            $pel = self::pelanggan()->where('id_lokasi', $l['id']);
            $aktif = $pel->filter(fn ($p) => $p['tgl_registrasi'] <= $endPeriode && (! $p['tgl_berhenti'] || $p['tgl_berhenti'] >= $periode));
            $tag = self::tagihan()->where('id_lokasi', $l['id'])->where('periode', $periode);
            $bay = self::pembayaran()->where('id_lokasi', $l['id'])->where('periode', $periode);
            $pemasukan = $bay->sum('jml_bayar');
            $pengeluaran = self::pengeluaran()->where('id_lokasi', $l['id'])->filter(fn ($p) => str_starts_with($p['waktu'], substr($periode, 0, 7)))->sum('nominal');
            $pajak = self::pajakLokasi($l['id']);
            $totalPg = $bay->whereIn('metode', ['tripay', 'xendit', 'pakar_digital'])->sum('jml_bayar');
            $dpp = round($pemasukan / (1 + $pajak['ppn_persen'] / 100));
            $ppn = $pemasukan - $dpp;
            $adminFee = $pajak['admin_fee_tipe'] === 'persentase' ? round($dpp * $pajak['admin_fee'] / 100) : $pajak['admin_fee'] * $bay->count();
            $dasar = $dpp - $adminFee;
            $bagiHasil = $l['metode_bagi_hasil'] === 'persentase' ? round($dasar * $l['nilai_bagi_hasil'] / 100) : ($pemasukan > 0 ? min($l['nilai_bagi_hasil'], $dasar) : 0);
            $omset = $dasar - $bagiHasil;
            $pph = round($omset * $pajak['pph_persen'] / 100);
            $harusSetor = $ppn + $adminFee + $bagiHasil + $pph;

            return [
                'id_lokasi' => $l['id'],
                'nama_cabang' => $l['nama_lokasi'],
                'jumlah_pelanggan' => $aktif->count(),
                'pelanggan_baru' => $pel->filter(fn ($p) => str_starts_with($p['tgl_registrasi'], substr($periode, 0, 7)))->count(),
                'pelanggan_free' => $aktif->where('harga', 0)->count(),
                'lunas' => $tag->where('status', 'lunas')->count(),
                'belum_lunas' => $tag->where('status', '!=', 'lunas')->count(),
                'pemasukan' => $pemasukan,
                'pemasukan_via_mitra' => $pemasukan - $totalPg,
                'total_cash' => $bay->where('metode', 'cash')->sum('jml_bayar'),
                'total_pg' => $totalPg,
                'dpp' => $dpp,
                'ppn_persen' => $pajak['ppn_persen'],
                'ppn' => $ppn,
                'admin_fee_label' => $pajak['admin_fee_tipe'] === 'persentase' ? $pajak['admin_fee'].'%' : 'Rp '.number_format($pajak['admin_fee'], 0, ',', '.').'/trx',
                'admin_fee' => $adminFee,
                'metode_bagi_hasil' => $l['metode_bagi_hasil'] === 'persentase' ? $l['nilai_bagi_hasil'].'%' : 'Nominal',
                'nominal_bagi_hasil' => $bagiHasil,
                'omset_reseller' => $omset,
                'pph_persen' => $pajak['pph_persen'],
                'pph' => $pph,
                'harus_setor' => $harusSetor,
                'pendapatan_reseller' => $omset - $pph,
                'pengeluaran' => $pengeluaran,
            ];
        });
    }

    public static function setorMitra(): Collection
    {
        return self::once('setor', function () {
            $f = self::faker();
            $rows = collect();
            $periods = array_slice(self::periods(), 0, 11);
            $rekPusat = self::rekening()->where('id_lokasi', 1)->values();
            foreach ($periods as $idx => $periode) {
                foreach (self::lokasi()->where('id', '>', 1) as $l) {
                    $base = self::rekapBase($l, $periode);
                    if ($base['harus_setor'] <= 0) {
                        continue;
                    }
                    $last = $idx === 10;
                    $status = $last ? $f->randomElement(['menunggu_verifikasi', 'menunggu_verifikasi', 'diterima', 'ditolak']) : 'diterima';
                    $rek = $f->randomElement($rekPusat->all());
                    $rows->push([
                        'id' => $rows->count() + 1,
                        'id_lokasi' => $l['id'],
                        'cabang' => $l['nama_lokasi'],
                        'periode' => $periode,
                        'nominal_harus_setor' => $base['harus_setor'],
                        'nominal_setor' => $f->boolean(85) ? $base['harus_setor'] : round($base['harus_setor'] * 0.6, -3),
                        'id_rekening' => $rek['id'],
                        'metode' => $rek['nama_rekening'],
                        'bukti' => 'setor-'.$l['kode_lokasi'].'-'.substr($periode, 0, 7).'.jpg',
                        'keterangan' => 'Setoran '.Carbon::parse($periode)->translatedFormat('F Y'),
                        'status' => $status,
                        'diverifikasi_oleh' => $status === 'menunggu_verifikasi' ? null : 'Admin Pusat',
                        'created_at' => Carbon::parse($periode)->addMonth()->addDays($f->numberBetween(1, 9))->setTime(10, 15)->toDateTimeString(),
                    ]);
                }
            }

            return $rows;
        });
    }

    public static function penarikanSaldo(): Collection
    {
        return self::once('penarikan', function () {
            $f = self::faker();
            $rows = collect();
            $periods = array_slice(self::periods(), 4, 7);
            foreach ($periods as $idx => $periode) {
                foreach ($f->randomElements(self::lokasi()->where('id', '>', 1)->values()->all(), 5) as $l) {
                    $base = self::rekapBase($l, $periode);
                    if ($base['pendapatan_reseller'] <= 100000) {
                        continue;
                    }
                    $status = $idx >= 5 ? $f->randomElement(['pending', 'disetujui', 'ditolak', 'pending']) : $f->randomElement(['dibayar', 'dibayar', 'disetujui']);
                    $rows->push([
                        'id' => $rows->count() + 1,
                        'id_lokasi' => $l['id'],
                        'cabang' => $l['nama_lokasi'],
                        'id_mitra' => $l['id_mitra'],
                        'nama_mitra' => $l['nama_mitra'],
                        'nama_request' => 'WD '.Carbon::parse($periode)->translatedFormat('M Y').' - '.explode(' ', $l['nama_lokasi'])[1],
                        'periode_sumber' => $periode,
                        'nominal' => round($base['pendapatan_reseller'] * $f->randomElement([0.5, 0.75, 1]), -3),
                        'keterangan' => 'Penarikan pendapatan reseller',
                        'status' => $status,
                        'bukti_transfer' => $status === 'dibayar' ? 'wd-'.$rows->count().'.jpg' : null,
                        'created_at' => Carbon::parse($periode)->addMonth()->addDays($f->numberBetween(2, 12))->setTime(11, 30)->min(self::today()->copy()->setTime(8, 0))->toDateTimeString(),
                        'diproses_oleh' => $status === 'pending' ? null : 'Admin Pusat',
                    ]);
                }
            }

            return $rows;
        });
    }

    /**
     * Rekap lengkap Dashboard Cabang untuk satu periode (Y-m-01).
     * Saldo / Balance = (Pemasukan via PG + Sudah Setor) − (Total Setor ke MSS + Sudah WD).
     * Negatif = mitra masih berutang ke MSS; positif = hak mitra yang masih ditahan MSS.
     */
    public static function rekapCabang(string $periode, ?int $idLokasi = null): array
    {
        return self::lokasi()
            ->when($idLokasi, fn ($c) => $c->where('id', $idLokasi))
            ->map(function ($l) use ($periode) {
                $base = self::rekapBase($l, $periode);
                $sudah = self::setorMitra()->where('id_lokasi', $l['id'])->where('periode', $periode)->where('status', 'diterima')->sum('nominal_setor');
                $wd = self::penarikanSaldo()->where('id_lokasi', $l['id'])->where('periode_sumber', $periode)->whereIn('status', ['disetujui', 'dibayar'])->sum('nominal');
                $harus = $l['id'] === 1 ? 0 : $base['harus_setor'];

                return array_merge($base, [
                    'harus_setor' => $harus,
                    'sudah_setor' => $sudah,
                    'sisa_setor' => max(0, $harus - $sudah),
                    'total_wd' => $wd,
                    'sisa_wd' => max(0, $base['pendapatan_reseller'] - $wd),
                    'saldo' => $base['total_pg'] + $sudah - $harus - $wd,
                ]);
            })->values()->all();
    }

    /** Rekap tahunan: akumulasi 12 bulan per cabang. */
    public static function rekapTahunan(int $tahun): array
    {
        $periods = collect(self::periods())->filter(fn ($p) => str_starts_with($p, (string) $tahun));
        $sum = [];
        foreach ($periods as $p) {
            foreach (self::rekapCabang($p) as $row) {
                $id = $row['id_lokasi'];
                if (! isset($sum[$id])) {
                    $sum[$id] = $row;

                    continue;
                }
                foreach ($row as $k => $v) {
                    if (is_numeric($v) && ! in_array($k, ['id_lokasi', 'jumlah_pelanggan', 'ppn_persen', 'pph_persen'])) {
                        $sum[$id][$k] += $v;
                    }
                }
                $sum[$id]['jumlah_pelanggan'] = $row['jumlah_pelanggan'];
            }
        }

        return array_values($sum);
    }

    public static function notifikasi(): Collection
    {
        return self::once('notifikasi', fn () => collect([
            ['Reminder H-3', 'reminder_tagihan', 'relatif_jatuh_tempo', -3, '09:00', 'belum_lunas', true, null, true],
            ['Reminder H-1', 'reminder_tagihan', 'relatif_jatuh_tempo', -1, '09:00', 'belum_lunas', true, null, true],
            ['Jatuh Tempo Hari Ini', 'jatuh_tempo', 'relatif_jatuh_tempo', 0, '08:00', 'belum_lunas', true, null, true],
            ['Pemberitahuan Isolir', 'isolir', 'relatif_jatuh_tempo', 3, '10:00', 'off', true, null, true],
            ['Bukti Pembayaran', 'bukti_bayar', 'relatif_jatuh_tempo', 0, '00:00', 'semua', false, null, true],
            ['Selamat Datang Pelanggan Baru', 'pelanggan_baru', 'relatif_jatuh_tempo', 0, '00:00', 'semua', false, null, true],
            ['Tagihan Serentak Tgl 1 Garut', 'reminder_tagihan', 'serentak_tanggal', 1, '07:30', 'semua', true, 3, false],
        ])->map(fn ($n, $i) => [
            'id' => $i + 1, 'nama_notifikasi' => $n[0], 'tipe' => $n[1], 'jadwal_tipe' => $n[2], 'offset_hari' => $n[3],
            'jam_kirim' => $n[4], 'target' => $n[5], 'tampilkan_link_bayar' => $n[6], 'id_lokasi' => $n[7],
            'cabang' => self::namaLokasi($n[7]), 'status' => $n[8],
            'isi_pesan' => "Halo {nama},\n\nTagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}.\nBayar dengan mudah melalui: {link}\n\nTerima kasih,\nMSS Net",
        ]));
    }

    public static function wagw(): array
    {
        return [
            'devices' => [
                ['id' => 1, 'nama_device' => 'WA Billing Utama', 'nomor' => '6281234567890', 'server_url' => 'wa1.mss.net.id', 'status' => 'connected', 'last_seen' => self::today()->copy()->setTime(13, 58)->toDateTimeString()],
                ['id' => 2, 'nama_device' => 'WA Cadangan', 'nomor' => '6281298765432', 'server_url' => 'wa2.mss.net.id', 'status' => 'disconnected', 'last_seen' => self::today()->copy()->subDays(2)->setTime(9, 12)->toDateTimeString()],
            ],
            'sent' => 284, 'outbox' => 17, 'failed' => 6,
        ];
    }

    public static function pphUpload(): Collection
    {
        return self::once('pph', function () {
            $rows = collect();
            foreach (array_slice(self::periods(), 6, 5) as $p) {
                foreach (self::lokasi()->take(6) as $l) {
                    $rows->push([
                        'id' => $rows->count() + 1, 'nama_pph' => 'PPh 25 '.Carbon::parse($p)->translatedFormat('F Y'), 'id_lokasi' => $l['id'],
                        'cabang' => $l['nama_lokasi'], 'periode' => $p, 'lampiran' => 'pph25-'.$l['kode_lokasi'].'-'.substr($p, 0, 7).'.pdf',
                        'keterangan' => 'Bukti potong PPh 25', 'uploaded_by' => 'Admin Pusat',
                        'created_at' => Carbon::parse($p)->addMonth()->addDays(9)->setTime(15, 20)->toDateTimeString(),
                    ]);
                }
            }

            return $rows;
        });
    }

    public static function tunnel(): Collection
    {
        return self::once('tunnel', fn () => self::router()->take(9)->map(fn ($r, $i) => [
            'id' => $i + 1, 'nama_tunnel' => 'tunnel-'.strtolower($r['kode_router']), 'username' => 'tnl'.sprintf('%03d', $i + 1),
            'id_router' => $r['id'], 'router' => $r['nama_router'], 'cabang' => $r['cabang'],
            'protokol' => $i % 3 ? 'L2TP' : 'SSTP', 'status' => $i % 5 === 4 ? 'tidak_aktif' : 'aktif',
        ])->values());
    }

    public static function paymentGateway(): Collection
    {
        return self::once('pg', function () {
            $cfg = [['tripay', 'join_ebilling', 1], ['xendit', 'join_ebilling', 1], ['pakar_digital', 'join_ebilling', 1], ['tripay', 'private', 2], ['xendit', 'private', 3], ['moota', 'private', 4], ['tripay', 'private', 6], ['pakar_digital', 'private', 8, false]];

            return collect($cfg)->map(fn ($c, $i) => [
                'id' => $i + 1, 'provider' => $c[0], 'mode' => $c[1], 'id_lokasi' => $c[2], 'cabang' => self::namaLokasi($c[2]),
                'merchant_code' => 'T'.(10000 + $i * 37), 'beban_biaya' => $i % 2 ? 'perusahaan' : 'pelanggan',
                'biaya_admin' => [4250, 4500, 3500, 4250, 4500, 2500, 4250, 3500][$i], 'status' => ($c[3] ?? true) ? 'aktif' : 'tidak_aktif',
            ]);
        });
    }

    public static function pgChannel(int $idPg): array
    {
        $list = [
            ['BCAVA', 'BCA Virtual Account'], ['BRIVA', 'BRI Virtual Account'], ['BNIVA', 'BNI Virtual Account'], ['MANDIRIVA', 'Mandiri Virtual Account'],
            ['PERMATAVA', 'Permata Virtual Account'], ['QRIS', 'QRIS (Semua E-Wallet)'], ['OVO', 'OVO'], ['DANA', 'DANA'], ['SHOPEEPAY', 'ShopeePay'], ['ALFAMART', 'Alfamart'], ['INDOMARET', 'Indomaret'],
        ];

        return collect($list)->map(fn ($c, $i) => ['id' => $i + 1, 'id_payment_gateway' => $idPg, 'kode_channel' => $c[0], 'nama_channel' => $c[1], 'aktif' => ($i + $idPg) % 4 !== 0])->all();
    }

    public static function roles(): Collection
    {
        return collect([
            ['id' => 1, 'nama' => 'Super Admin', 'keterangan' => 'Akses penuh seluruh sistem', 'is_locked' => true, 'jumlah_user' => 2],
            ['id' => 2, 'nama' => 'Admin', 'keterangan' => 'Admin operasional pusat', 'is_locked' => false, 'jumlah_user' => 3],
            ['id' => 3, 'nama' => 'Mitra', 'keterangan' => 'Pemilik/pengelola cabang', 'is_locked' => false, 'jumlah_user' => 9],
            ['id' => 4, 'nama' => 'Sales', 'keterangan' => 'Registrasi pelanggan baru', 'is_locked' => false, 'jumlah_user' => 4],
            ['id' => 5, 'nama' => 'Teknisi', 'keterangan' => 'Instalasi & gangguan', 'is_locked' => false, 'jumlah_user' => 6],
        ]);
    }

    /** Daftar menu untuk matriks hak akses. */
    public static function permissionMenus(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard'], ['key' => 'dashboard_cabang', 'label' => 'Dashboard Cabang'], ['key' => 'income_summary', 'label' => 'Income Summary'],
            ['key' => 'kelola_data.kartu_member', 'label' => 'Kelola Data › Kartu Member'], ['key' => 'kelola_data.cabang', 'label' => 'Kelola Data › Cabang / Lokasi'],
            ['key' => 'kelola_data.pajak', 'label' => 'Kelola Data › Pengaturan Pajak'], ['key' => 'kelola_data.paket', 'label' => 'Kelola Data › Paket Pelanggan'],
            ['key' => 'kelola_data.router', 'label' => 'Kelola Data › Router'], ['key' => 'kelola_data.rekening', 'label' => 'Kelola Data › Rekening'],
            ['key' => 'kelola_data.notifikasi', 'label' => 'Kelola Data › Notifikasi'], ['key' => 'kelola_data.pph', 'label' => 'Kelola Data › Upload Bukti PPh'],
            ['key' => 'kelola_data.tunnel', 'label' => 'Kelola Data › Tunnel'], ['key' => 'transaksi.penarikan', 'label' => 'Transaksi › Penarikan Saldo Mitra'],
            ['key' => 'transaksi.setor', 'label' => 'Transaksi › Setor Kewajiban Mitra'], ['key' => 'transaksi.pengeluaran', 'label' => 'Transaksi › Pengeluaran'],
            ['key' => 'laporan', 'label' => 'Laporan'], ['key' => 'payment_gateway', 'label' => 'Payment Gateway'], ['key' => 'logs', 'label' => 'Logs'],
            ['key' => 'user.manajemen', 'label' => 'User › Manajemen User'], ['key' => 'user.hak_akses', 'label' => 'User › Hak Akses'],
        ];
    }

    public static function rolePermissions(int $idRole): array
    {
        $actions = ['view', 'create', 'update', 'delete', 'export', 'approve'];
        $out = [];
        foreach (self::permissionMenus() as $i => $m) {
            foreach ($actions as $j => $a) {
                $out[$m['key']][$a] = match ($idRole) {
                    1 => true,
                    2 => ! str_starts_with($m['key'], 'user.') || $a === 'view',
                    3 => in_array($m['key'], ['dashboard', 'dashboard_cabang', 'transaksi.penarikan', 'transaksi.setor', 'transaksi.pengeluaran', 'laporan']) && $j < 3,
                    default => $a === 'view' && $i < 3,
                };
            }
        }

        return $out;
    }

    public static function users(): Collection
    {
        return self::once('users', function () {
            $f = self::faker();
            $rows = collect([
                ['id' => 1, 'nama' => 'Hilman Aji', 'username' => 'superadmin', 'id_role' => 1],
                ['id' => 2, 'nama' => 'Admin Pusat', 'username' => 'admin.pusat', 'id_role' => 2],
            ]);
            foreach (self::lokasi()->where('id', '>', 1) as $l) {
                $rows->push(['id' => $l['id_mitra'], 'nama' => $l['nama_mitra'], 'username' => 'mitra.'.strtolower(explode(' ', $l['nama_lokasi'])[1]), 'id_role' => 3, 'lokasi' => [$l['id']]]);
            }
            foreach (['Rina Kasir' => 2, 'Sari Admin' => 2, 'Dodi Teknisi' => 5, 'Agus Sales' => 4, 'Bayu Teknisi' => 5] as $n => $r) {
                $rows->push(['id' => $rows->count() + 10, 'nama' => $n, 'username' => strtolower(str_replace(' ', '.', $n)), 'id_role' => $r]);
            }

            return $rows->map(fn ($u) => $u + [
                'email' => $u['username'].'@mss.net.id',
                'tlp' => '08'.$f->numerify('##########'),
                'role' => self::roles()->firstWhere('id', $u['id_role'])['nama'],
                'lokasi' => $u['lokasi'] ?? ($u['id_role'] <= 2 ? [] : [1, 2]),
                'status' => $u['id'] === 16 ? 'nonaktif' : 'aktif',
                'last_login_at' => self::today()->copy()->subHours($f->numberBetween(1, 200))->toDateTimeString(),
                'last_login_ip' => $f->ipv4(),
            ])->map(fn ($u) => $u + ['cabang_akses' => $u['lokasi'] ? self::lokasi()->whereIn('id', $u['lokasi'])->pluck('nama_lokasi')->implode(', ') : 'Semua Cabang']);
        });
    }

    public static function activityLog(): Collection
    {
        return self::once('logs', function () {
            $f = self::faker();
            $users = self::users();
            $mods = [
                ['Pembayaran', 'create', 'Input pembayaran INV2610%05d'], ['Pelanggan', 'update', 'Mengubah data pelanggan C001%04d'],
                ['Paket', 'update', 'Mengubah harga paket #%d'], ['Setor Mitra', 'approve', 'Verifikasi setoran #%d'],
                ['Penarikan Saldo', 'reject', 'Menolak penarikan saldo #%d'], ['Router', 'delete', 'Menghapus router RTR-%03d'],
                ['Laporan', 'export', 'Export laporan bulanan #%d'], ['Auth', 'login', 'Login berhasil'], ['Auth', 'logout', 'Logout'],
                ['Cabang', 'create', 'Menambah cabang C%03d'],
            ];
            $agents = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/141.0', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) Safari/18.1', 'Mozilla/5.0 (Linux; Android 14) Chrome/140.0 Mobile'];

            return collect(range(1, 120))->map(function ($i) use ($f, $users, $mods, $agents) {
                $m = $f->randomElement($mods);
                $u = $f->randomElement($users->all());
                $hasDiff = in_array($m[1], ['update', 'create', 'delete', 'approve', 'reject']);

                return [
                    'id' => $i,
                    'created_at' => self::today()->copy()->setTime(15, 0)->subMinutes($i * 47 + $f->numberBetween(0, 40))->toDateTimeString(),
                    'user' => $u['nama'], 'role' => $u['role'], 'modul' => $m[0], 'aksi' => $m[1],
                    'deskripsi' => str_contains($m[2], '%') ? sprintf($m[2], $f->numberBetween(1, 300)) : $m[2],
                    'ip_address' => $u['last_login_ip'], 'user_agent' => $f->randomElement($agents),
                    'data_lama' => $hasDiff && $m[1] !== 'create' ? ['harga' => 200000, 'status' => 'pending', 'keterangan' => 'Lama'] : null,
                    'data_baru' => $hasDiff && $m[1] !== 'delete' ? ['harga' => 225000, 'status' => $m[1] === 'reject' ? 'ditolak' : 'disetujui', 'keterangan' => 'Baru'] : null,
                ];
            });
        });
    }
}
