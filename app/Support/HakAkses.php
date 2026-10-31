<?php

namespace App\Support;

use App\Models\User;

/**
 * Katalog hak akses (spatie/laravel-permission). Nama permission = "{menu}.{aksi}", mis. "pelanggan.data.create".
 * Dipakai routes (middleware permission:), HakAksesSeeder, halaman Hak Akses, dan tujuan setelah login.
 * Super Admin lolos semua pemeriksaan lewat Gate::before (AppServiceProvider), jadi tidak perlu permission.
 */
class HakAkses
{
    public const SUPER_ADMIN = 'Super Admin';

    public const AKSI = ['view' => 'Lihat', 'create' => 'Tambah', 'update' => 'Ubah', 'delete' => 'Hapus', 'export' => 'Export', 'approve' => 'Approve'];

    private const CRUD = ['view', 'create', 'update', 'delete'];

    /** @var array<string, array{0: string, 1: string, 2: list<string>}> key => [label, halaman, aksi yang berlaku] */
    public const MENU = [
        'dashboard' => ['Dashboard', '/dashboard', ['view']],
        'dashboard_cabang' => ['Dashboard Cabang', '/dashboard-cabang', ['view', 'export']],
        'income_summary' => ['Income Summary', '/income-summary', ['view', 'export']],
        'pelanggan.data' => ['Pelanggan › Data Pelanggan', '/pelanggan', [...self::CRUD, 'export']],
        'pelanggan.on' => ['Pelanggan › Pelanggan On (Ubah = matikan/isolir)', '/pelanggan/on', ['view', 'update']],
        'pelanggan.off' => ['Pelanggan › Pelanggan Off (Ubah = nyalakan kembali)', '/pelanggan/off', ['view', 'update']],
        'pelanggan.lunas' => ['Pelanggan › Pelanggan Lunas', '/pelanggan/lunas', ['view']],
        'pelanggan.belum_lunas' => ['Pelanggan › Pelanggan Belum Lunas', '/pelanggan/belum-lunas', ['view']],
        'pelanggan.berhenti' => ['Pelanggan › Pelanggan Berhenti', '/pelanggan/berhenti', ['view']],
        'pelanggan.prorate' => ['Pelanggan › Tagihan Prorate', '/pelanggan/prorate', ['view']],
        'pelanggan.pembayaran' => ['Pelanggan › Pembayaran (Lihat = riwayat & kwitansi, Tambah = input, Ubah = edit, Hapus = batalkan)', '/pelanggan', self::CRUD],
        'kelola_data.kartu_member' => ['Kelola Data › Kartu Member', '/kelola-data/kartu-member', ['view', 'export']],
        'kelola_data.cabang' => ['Kelola Data › Cabang / Lokasi', '/kelola-data/cabang', self::CRUD],
        'kelola_data.area' => ['Kelola Data › Area', '/kelola-data/area', self::CRUD],
        'kelola_data.pajak' => ['Kelola Data › Pengaturan Pajak', '/kelola-data/pajak', ['view', 'update']],
        'kelola_data.paket' => ['Kelola Data › Paket Pelanggan', '/kelola-data/paket', self::CRUD],
        'kelola_data.router' => ['Kelola Data › Router', '/kelola-data/router', self::CRUD],
        'kelola_data.rekening' => ['Kelola Data › Rekening', '/kelola-data/rekening', self::CRUD],
        'kelola_data.notifikasi' => ['Kelola Data › Notifikasi', '/kelola-data/notifikasi', self::CRUD],
        'kelola_data.pph' => ['Kelola Data › Upload Bukti PPh', '/kelola-data/pph', ['view', 'create', 'delete']],
        'kelola_data.tunnel' => ['Kelola Data › Tunnel', '/kelola-data/tunnel', self::CRUD],
        'kelola_data.template_isolir' => ['Kelola Data › Template Isolir', '/kelola-data/template-isolir', ['view', 'update']],
        'transaksi.penarikan' => ['Transaksi › Penarikan Saldo Mitra', '/transaksi/penarikan-saldo', ['view', 'create', 'approve']],
        'transaksi.setor' => ['Transaksi › Setor Kewajiban Mitra', '/transaksi/setor-kewajiban', ['view', 'create', 'approve']],
        'transaksi.pengeluaran' => ['Transaksi › Pengeluaran', '/transaksi/pengeluaran', self::CRUD],
        'laporan' => ['Laporan', '/laporan', ['view', 'export']],
        'payment_gateway' => ['Payment Gateway', '/payment-gateway', self::CRUD],
        'logs' => ['Logs', '/logs', ['view']],
        'user.manajemen' => ['User › Manajemen User', '/user/manajemen', ['view', 'create', 'update']],
        'user.hak_akses' => ['User › Hak Akses', '/user/hak-akses', self::CRUD],
    ];

    /** Role bawaan & keterangannya; permission bawaannya hanya diberikan saat role pertama kali dibuat. */
    public const ROLE = [
        self::SUPER_ADMIN => 'Akses penuh seluruh sistem',
        'Admin' => 'Admin operasional pusat',
        'Mitra' => 'Pemilik / pengelola cabang',
        'Sales' => 'Registrasi pelanggan baru',
        'Teknisi' => 'Instalasi & gangguan',
    ];

    /** Menu yang bisa mengubah konfigurasi router secara langsung atau mengatur akun; bawaan hanya Super Admin. */
    private const KHUSUS_SUPER_ADMIN = ['kelola_data.router', 'kelola_data.tunnel', 'kelola_data.template_isolir', 'user.manajemen', 'user.hak_akses'];

    /** @return list<string> semua nama permission */
    public static function permissions(): array
    {
        return self::untukMenu(array_keys(self::MENU));
    }

    /** @return list<string> */
    public static function bawaan(string $role): array
    {
        return match ($role) {
            'Admin' => self::untukMenu(array_diff(array_keys(self::MENU), self::KHUSUS_SUPER_ADMIN)),
            'Mitra' => self::untukMenu(['dashboard', 'dashboard_cabang', 'laporan', 'transaksi.penarikan', 'transaksi.setor', 'transaksi.pengeluaran'], ['view', 'create', 'update']),
            'Sales' => ['dashboard.view', 'pelanggan.data.view', 'pelanggan.data.create'],
            'Teknisi' => ['dashboard.view', 'pelanggan.data.view', 'pelanggan.on.view', 'pelanggan.off.view'],
            default => [],
        };
    }

    /** Halaman pertama yang boleh dibuka user (tujuan setelah login), null bila tidak punya akses sama sekali. */
    public static function halamanAwal(User $user): ?string
    {
        foreach (self::MENU as $key => [, $halaman]) {
            if ($user->can("$key.view")) {
                return $halaman;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $menu
     * @param  list<string>|null  $aksi  batasi ke aksi ini (null = semua aksi yang berlaku di menu)
     * @return list<string>
     */
    private static function untukMenu(array $menu, ?array $aksi = null): array
    {
        $hasil = [];
        foreach ($menu as $key) {
            foreach (self::MENU[$key][2] as $a) {
                if ($aksi === null || in_array($a, $aksi, true)) {
                    $hasil[] = "$key.$a";
                }
            }
        }

        return $hasil;
    }
}
