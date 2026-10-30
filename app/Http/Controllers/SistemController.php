<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * User & profil memakai tabel users. Laporan, payment gateway, logs, dan role belum punya tabel,
 * sehingga tampil kosong sampai terhubung ke database.
 */
class SistemController extends Controller
{
    public function laporan(Request $request)
    {
        return Inertia::render('Laporan/Index', [
            'filters' => $this->filters($request) + ['mode' => $request->input('mode', 'bulanan')],
            'rows' => [],
            // Tahun yang tercakup 12 bulan terakhir
            'tahunList' => collect(range(11, 0))->map(fn ($i) => today()->startOfMonth()->subMonths($i)->year)->unique()->values(),
        ]);
    }

    public function paymentGateway(Request $request)
    {
        return Inertia::render('PaymentGateway/Index', [
            'filters' => $this->filters($request),
            'rows' => [],
        ]);
    }

    /** Channel tersedia setelah tabel payment gateway ada. */
    public function pgChannel(int $id)
    {
        abort(404);
    }

    public function logs(Request $request)
    {
        return Inertia::render('Logs/Index', [
            'filters' => $request->only(['dari', 'sampai', 'user', 'modul', 'aksi']),
            'rows' => [],
            'options' => ['user' => [], 'modul' => []],
        ]);
    }

    public function users()
    {
        return Inertia::render('User/Manajemen', [
            'rows' => User::orderBy('name')->get()->map(fn (User $u) => $this->barisUser($u)),
            'roleOptions' => [],
        ]);
    }

    public function hakAkses(Request $request)
    {
        return Inertia::render('User/HakAkses', [
            'roles' => [],
            'selected' => $request->filled('role') ? $request->integer('role') : null,
            'menus' => $this->permissionMenus(),
            'permissions' => (object) [],
        ]);
    }

    public function profil(Request $request)
    {
        return Inertia::render('User/Profil', ['user' => $this->barisUser($request->user())]);
    }

    /**
     * Data user untuk halaman. Tlp, status, dan riwayat login belum disimpan di tabel users;
     * semua user saat ini bisa login dan mengakses semua cabang.
     *
     * @return array<string, mixed>
     */
    private function barisUser(User $user): array
    {
        return [
            'id' => $user->id,
            'nama' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'tlp' => null,
            'role' => $user->role,
            'cabang_akses' => 'Semua Cabang',
            'status' => 'aktif',
            'last_login_at' => null,
            'last_login_ip' => null,
        ];
    }

    /**
     * Daftar menu aplikasi untuk matriks hak akses.
     *
     * @return list<array{key: string, label: string}>
     */
    private function permissionMenus(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard'], ['key' => 'dashboard_cabang', 'label' => 'Dashboard Cabang'], ['key' => 'income_summary', 'label' => 'Income Summary'],
            ['key' => 'pelanggan.data', 'label' => 'Pelanggan › Data Pelanggan'], ['key' => 'pelanggan.on', 'label' => 'Pelanggan › Pelanggan On'],
            ['key' => 'pelanggan.off', 'label' => 'Pelanggan › Pelanggan Off'],
            ['key' => 'kelola_data.kartu_member', 'label' => 'Kelola Data › Kartu Member'], ['key' => 'kelola_data.cabang', 'label' => 'Kelola Data › Cabang / Lokasi'],
            ['key' => 'kelola_data.area', 'label' => 'Kelola Data › Area'],
            ['key' => 'kelola_data.pajak', 'label' => 'Kelola Data › Pengaturan Pajak'], ['key' => 'kelola_data.paket', 'label' => 'Kelola Data › Paket Pelanggan'],
            ['key' => 'kelola_data.router', 'label' => 'Kelola Data › Router'], ['key' => 'kelola_data.rekening', 'label' => 'Kelola Data › Rekening'],
            ['key' => 'kelola_data.notifikasi', 'label' => 'Kelola Data › Notifikasi'], ['key' => 'kelola_data.pph', 'label' => 'Kelola Data › Upload Bukti PPh'],
            ['key' => 'kelola_data.tunnel', 'label' => 'Kelola Data › Tunnel'],
            ['key' => 'transaksi.penarikan', 'label' => 'Transaksi › Penarikan Saldo Mitra'],
            ['key' => 'transaksi.setor', 'label' => 'Transaksi › Setor Kewajiban Mitra'], ['key' => 'transaksi.pengeluaran', 'label' => 'Transaksi › Pengeluaran'],
            ['key' => 'laporan', 'label' => 'Laporan'], ['key' => 'payment_gateway', 'label' => 'Payment Gateway'], ['key' => 'logs', 'label' => 'Logs'],
            ['key' => 'user.manajemen', 'label' => 'User › Manajemen User'], ['key' => 'user.hak_akses', 'label' => 'User › Hak Akses'],
        ];
    }
}
