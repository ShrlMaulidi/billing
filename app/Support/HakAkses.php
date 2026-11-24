<?php

namespace App\Support;

use App\Models\User;
use App\Services\AkunPelanggan;

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
        'data_pelanggan' => ['Data Klien (Reseller, Korporat, Instansi Pemerintah, Shafa.net) — khusus user pusat', '/data-pelanggan', self::CRUD],
        'pelanggan.data' => ['Pelanggan › Data Pelanggan', '/pelanggan/data', [...self::CRUD, 'export']],
        'pelanggan.on' => ['Pelanggan › Pelanggan On (Ubah = matikan/isolir)', '/pelanggan/on', ['view', 'update']],
        'pelanggan.off' => ['Pelanggan › Pelanggan Off (Ubah = nyalakan kembali)', '/pelanggan/off', ['view', 'update']],
        'pelanggan.lunas' => ['Pelanggan › Pelanggan Lunas', '/pelanggan/lunas', ['view']],
        'pelanggan.belum_lunas' => ['Pelanggan › Pelanggan Belum Lunas (Ubah = ingatkan tagihan via WhatsApp cabang, juga di Tagihan Prorate & Pelanggan Off)', '/pelanggan/belum-lunas', ['view', 'update']],
        'pelanggan.berhenti' => ['Pelanggan › Pelanggan Berhenti', '/pelanggan/berhenti', ['view']],
        'pelanggan.prorate' => ['Pelanggan › Tagihan Prorate', '/pelanggan/prorate', ['view']],
        'pelanggan.pembayaran' => ['Pelanggan › Pembayaran (Lihat = riwayat & kwitansi, Tambah = input, Ubah = edit, Hapus = batalkan)', '/pelanggan/data', self::CRUD],
        'tiket' => ['Tiket (keluhan dari pelanggan, per cabang; Ubah = balas & ubah status)', '/tiket', ['view', 'update']],
        'kelola_data.kartu_member' => ['Kelola Data › Kartu Member', '/kelola-data/kartu-member', ['view', 'export']],
        'kelola_data.cabang' => ['Kelola Data › Cabang / Lokasi (tambah cabang lewat Data Klien)', '/kelola-data/cabang', ['view', 'update', 'delete']],
        'kelola_data.area' => ['Kelola Data › Area', '/kelola-data/area', self::CRUD],
        'kelola_data.pajak' => ['Kelola Data › Pengaturan Pajak', '/kelola-data/pajak', ['view', 'update']],
        'kelola_data.paket' => ['Kelola Data › Paket Pelanggan', '/kelola-data/paket', self::CRUD],
        'kelola_data.router' => ['Kelola Data › Router', '/kelola-data/router', self::CRUD],
        'kelola_data.rekening' => ['Kelola Data › Rekening', '/kelola-data/rekening', self::CRUD],
        'kelola_data.notifikasi' => ['Kelola Data › Notifikasi (WhatsApp cabang & template tagihan; Ubah = scan/putuskan WhatsApp & ubah template)', '/kelola-data/notifikasi', ['view', 'update']],
        'kelola_data.pph' => ['Kelola Data › Upload Bukti PPh', '/kelola-data/pph', ['view', 'create', 'delete']],
        'kelola_data.tunnel' => ['Kelola Data › Tunnel', '/kelola-data/tunnel', self::CRUD],
        'kelola_data.template_isolir' => ['Kelola Data › Template Isolir', '/kelola-data/template-isolir', ['view', 'update']],
        'transaksi.penarikan' => ['Transaksi › Penarikan Saldo Mitra', '/transaksi/penarikan-saldo', ['view', 'create', 'approve']],
        'transaksi.setor' => ['Transaksi › Pembayaran Masuk (Tambah & Hapus khusus user pusat)', '/transaksi/setor-kewajiban', ['view', 'create', 'delete']],
        'transaksi.pengeluaran' => ['Transaksi › Pengeluaran', '/transaksi/pengeluaran', self::CRUD],
        'laporan.dashboard' => ['Laporan › Dashboard Laporan (Mitra, Instansi & Korporat yang sudah / belum bayar; khusus user pusat)', '/laporan/dashboard', ['view']],
        'laporan' => ['Laporan › Semua Laporan', '/laporan', ['view', 'export']],
        'laporan.mitra' => ['Laporan › Laporan Mitra', '/laporan/mitra', ['view', 'export']],
        'laporan.pemerintah' => ['Laporan › Laporan Instansi Pemerintah (khusus user pusat)', '/laporan/pemerintah', ['view', 'export']],
        'laporan.korporat' => ['Laporan › Laporan Korporat (khusus user pusat)', '/laporan/korporat', ['view', 'export']],
        'laporan.shafanet' => ['Laporan › Laporan Shafa Net', '/laporan/shafa-net', ['view', 'export']],
        'payment_gateway' => ['Payment Gateway', '/payment-gateway', self::CRUD],
        'logs' => ['Logs', '/logs', ['view']],
        'user.manajemen' => ['User › Manajemen User', '/user/manajemen', ['view', 'create', 'update']],
        'user.akun_pelanggan' => ['User › Akun Pelanggan (Ubah = reset password & aktif/nonaktif)', '/user/akun-pelanggan', ['view', 'update']],
        'user.hak_akses' => ['User › Hak Akses', '/user/hak-akses', self::CRUD],
    ];

    /** Role bawaan & keterangannya; permission bawaannya hanya diberikan saat role pertama kali dibuat. */
    public const ROLE = [
        self::SUPER_ADMIN => 'Akses penuh seluruh sistem',
        'Admin' => 'Admin operasional pusat',
        'Mitra' => 'Pemilik / pengelola cabang',
        'Sales' => 'Registrasi pelanggan baru',
        'Teknisi' => 'Instalasi & gangguan',
        'Direktur' => 'Monitoring laporan & rekap seluruh cabang (hanya lihat & export laporan)',
        'Manajemen' => 'Monitoring laporan & rekap seluruh cabang (hanya lihat)',
        AkunPelanggan::ROLE => AkunPelanggan::KETERANGAN_ROLE,
    ];

    /** Menu yang bisa mengubah konfigurasi router secara langsung atau mengatur akun; bawaan hanya Super Admin. */
    private const KHUSUS_SUPER_ADMIN = ['kelola_data.router', 'kelola_data.tunnel', 'kelola_data.template_isolir', 'user.manajemen', 'user.hak_akses'];

    /**
     * Menu yang dibuka role monitoring (Direktur & Manajemen), hanya aksi Lihat: tidak bisa mengubah data, mematikan
     * pelanggan, mengirim WhatsApp, atau mencatat pembayaran.
     */
    private const MENU_MONITORING = ['dashboard', 'dashboard_cabang', 'income_summary',
        'laporan.dashboard', 'laporan', 'laporan.mitra', 'laporan.pemerintah', 'laporan.korporat', 'laporan.shafanet',
        'transaksi.setor', 'transaksi.penarikan', 'transaksi.pengeluaran',
        'pelanggan.data', 'pelanggan.on', 'pelanggan.off', 'pelanggan.lunas', 'pelanggan.belum_lunas', 'pelanggan.berhenti', 'pelanggan.prorate',
        'tiket'];

    /** Laporan yang boleh di-export role monitoring; export Data Pelanggan (nama & nomor HP) sengaja tidak diberikan. */
    private const LAPORAN_MONITORING = ['dashboard_cabang', 'laporan', 'laporan.mitra', 'laporan.pemerintah', 'laporan.korporat', 'laporan.shafanet'];

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
            // Payment gateway: hanya cabang Admin Full yang bisa menambah gateway sendiri (dicek di PaymentGatewayController)
            'Mitra' => [...self::untukMenu(['dashboard', 'dashboard_cabang', 'tiket', 'laporan', 'laporan.mitra', 'transaksi.penarikan', 'transaksi.setor', 'transaksi.pengeluaran'], ['view', 'create', 'update']),
                ...self::untukMenu(['payment_gateway', 'kelola_data.notifikasi'])],
            'Sales' => ['dashboard.view', 'pelanggan.data.view', 'pelanggan.data.create'],
            'Teknisi' => ['dashboard.view', 'pelanggan.data.view', 'pelanggan.on.view', 'pelanggan.off.view', 'tiket.view', 'tiket.update'],
            'Direktur' => [...self::untukMenu(self::MENU_MONITORING, ['view']), ...self::untukMenu([...self::LAPORAN_MONITORING, 'income_summary'], ['export']), 'logs.view'],
            'Manajemen' => [...self::untukMenu(self::MENU_MONITORING, ['view']), ...self::untukMenu(self::LAPORAN_MONITORING, ['export'])],
            default => [],
        };
    }

    /**
     * Halaman pertama yang boleh dibuka user (tujuan setelah login), null bila tidak punya akses sama sekali.
     * Akun pelanggan hanya membuka halaman Akun Saya, atau halaman buat password baru selama masih memakai password awal.
     */
    public static function halamanAwal(User $user): ?string
    {
        if ($user->isPelanggan()) {
            return $user->wajib_ganti_password ? '/akun-saya/password-baru' : '/akun-saya';
        }
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
