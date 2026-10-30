<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CabangController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HakAksesController;
use App\Http\Controllers\IsolirController;
use App\Http\Controllers\KelolaDataController;
use App\Http\Controllers\PaketController;
use App\Http\Controllers\PelangganController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\SistemController;
use App\Http\Controllers\TransaksiController;
use App\Http\Controllers\TunnelController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
// Tujuan redirect web proxy router untuk pelanggan terisolir (tanpa login)
Route::get('/isolir', [IsolirController::class, 'halaman'])->name('isolir');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

/*
 * Semua halaman aplikasi & aksi Mikrotik wajib login, dan tiap route dijaga permission spatie
 * "{menu}.{aksi}" (daftar lengkap di App\Support\HakAkses, diatur lewat menu User › Hak Akses).
 */
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    // Profil sendiri boleh dibuka semua user yang login
    Route::get('/profil', [SistemController::class, 'profil'])->name('profil');

    Route::controller(DashboardController::class)->group(function () {
        Route::get('/dashboard', 'index')->middleware('permission:dashboard.view')->name('dashboard');
        Route::get('/dashboard/pelanggan', 'pelanggan')->middleware('permission:dashboard.view')->name('dashboard.pelanggan');
        Route::get('/dashboard-cabang', 'cabang')->middleware('permission:dashboard_cabang.view')->name('dashboard-cabang');
        Route::get('/dashboard-cabang/{id}', 'cabangDetail')->middleware('permission:dashboard_cabang.view')->name('dashboard-cabang.detail');
        Route::get('/income-summary', 'income')->middleware('permission:income_summary.view')->name('income-summary');
    });

    Route::prefix('kelola-data')->name('kelola-data.')->controller(KelolaDataController::class)->group(function () {
        Route::get('/kartu-member', 'kartuMember')->middleware('permission:kelola_data.kartu_member.view')->name('kartu-member');
        Route::get('/pajak', 'pajak')->middleware('permission:kelola_data.pajak.view')->name('pajak');
        Route::get('/rekening', 'rekening')->middleware('permission:kelola_data.rekening.view')->name('rekening');
        Route::get('/notifikasi', 'notifikasi')->middleware('permission:kelola_data.notifikasi.view')->name('notifikasi');
        Route::get('/pph', 'pph')->middleware('permission:kelola_data.pph.view')->name('pph');
    });

    // Cabang & area (database)
    Route::get('/kelola-data/cabang', [CabangController::class, 'index'])->middleware('permission:kelola_data.cabang.view')->name('kelola-data.cabang');
    Route::post('/cabang', [CabangController::class, 'store'])->middleware('permission:kelola_data.cabang.create')->name('cabang.store');
    Route::put('/cabang/{lokasi}', [CabangController::class, 'update'])->middleware('permission:kelola_data.cabang.update')->name('cabang.update');
    Route::delete('/cabang/{lokasi}', [CabangController::class, 'destroy'])->middleware('permission:kelola_data.cabang.delete')->name('cabang.destroy');
    Route::get('/kelola-data/area', [AreaController::class, 'index'])->middleware('permission:kelola_data.area.view')->name('kelola-data.area');
    Route::post('/area', [AreaController::class, 'store'])->middleware('permission:kelola_data.area.create')->name('area.store');
    Route::put('/area/{area}', [AreaController::class, 'update'])->middleware('permission:kelola_data.area.update')->name('area.update');
    Route::delete('/area/{area}', [AreaController::class, 'destroy'])->middleware('permission:kelola_data.area.delete')->name('area.destroy');

    // Data pelanggan (database) — akun koneksi di Mikrotik & On/Off internet lewat isolir PPPoE
    Route::get('/pelanggan', [PelangganController::class, 'index'])->middleware('permission:pelanggan.data.view')->name('pelanggan.index');
    Route::get('/pelanggan/on', [PelangganController::class, 'index'])->defaults('status', 'on')->middleware('permission:pelanggan.on.view')->name('pelanggan.on');
    Route::get('/pelanggan/off', [PelangganController::class, 'index'])->defaults('status', 'off')->middleware('permission:pelanggan.off.view')->name('pelanggan.off');
    Route::post('/pelanggan', [PelangganController::class, 'store'])->middleware('permission:pelanggan.data.create')->name('pelanggan.store');
    Route::put('/pelanggan/{pelanggan}', [PelangganController::class, 'update'])->middleware('permission:pelanggan.data.update')->name('pelanggan.update');
    Route::delete('/pelanggan/{pelanggan}', [PelangganController::class, 'destroy'])->middleware('permission:pelanggan.data.delete')->name('pelanggan.destroy');

    // Paket langganan (database) — profile paket bisa disinkronkan ke Mikrotik
    Route::get('/kelola-data/paket', [PaketController::class, 'index'])->middleware('permission:kelola_data.paket.view')->name('kelola-data.paket');
    Route::post('/paket', [PaketController::class, 'store'])->middleware('permission:kelola_data.paket.create')->name('paket.store');
    Route::put('/paket/{paket}', [PaketController::class, 'update'])->middleware('permission:kelola_data.paket.update')->name('paket.update');
    Route::delete('/paket/{paket}', [PaketController::class, 'destroy'])->middleware('permission:kelola_data.paket.delete')->name('paket.destroy');

    // Aksi yang langsung menghubungi router dibatasi frekuensinya (lihat AppServiceProvider)
    Route::middleware('throttle:mikrotik')->group(function () {
        Route::post('/pelanggan/{pelanggan}/isolir', [PelangganController::class, 'isolir'])->middleware('permission:pelanggan.on.update')->name('pelanggan.isolir');
        Route::post('/pelanggan/{pelanggan}/aktifkan', [PelangganController::class, 'aktifkan'])->middleware('permission:pelanggan.off.update')->name('pelanggan.aktifkan');
        Route::get('/pelanggan/akun-router/{router}', [PelangganController::class, 'akunRouter'])->middleware('permission:pelanggan.data.create|pelanggan.data.update')->name('pelanggan.akun-router');
        Route::post('/paket/{paket}/sinkron', [PaketController::class, 'sinkron'])->middleware('permission:kelola_data.paket.update')->name('paket.sinkron');
        // Daftar profile dipakai saat menyalakan pelanggan yang profile asalnya tidak diketahui
        Route::get('/mikrotik/router/{router}/profiles', [RouterController::class, 'profiles'])->middleware('permission:pelanggan.off.update|kelola_data.router.view')->name('mikrotik.router.profiles');
    });

    Route::prefix('transaksi')->name('transaksi.')->controller(TransaksiController::class)->group(function () {
        Route::get('/penarikan-saldo', 'penarikan')->middleware('permission:transaksi.penarikan.view')->name('penarikan');
        Route::get('/setor-kewajiban', 'setor')->middleware('permission:transaksi.setor.view')->name('setor');
        Route::get('/pengeluaran', 'pengeluaran')->middleware('permission:transaksi.pengeluaran.view')->name('pengeluaran');
    });

    Route::controller(SistemController::class)->group(function () {
        Route::get('/laporan', 'laporan')->middleware('permission:laporan.view')->name('laporan');
        Route::get('/payment-gateway', 'paymentGateway')->middleware('permission:payment_gateway.view')->name('payment-gateway');
        Route::get('/payment-gateway/{id}/channel', 'pgChannel')->middleware('permission:payment_gateway.view')->name('payment-gateway.channel');
        Route::get('/logs', 'logs')->middleware('permission:logs.view')->name('logs');
    });

    // Router memakai database + koneksi Mikrotik sungguhan; data router dibatasi per cabang di controller
    Route::get('/kelola-data/router', [RouterController::class, 'index'])->middleware('permission:kelola_data.router.view')->name('kelola-data.router');
    Route::prefix('mikrotik')->name('mikrotik.')->group(function () {
        Route::post('/router', [RouterController::class, 'store'])->middleware('permission:kelola_data.router.create')->name('router.store');
        Route::put('/router/{router}', [RouterController::class, 'update'])->middleware('permission:kelola_data.router.update')->name('router.update');
        Route::delete('/router/{router}', [RouterController::class, 'destroy'])->middleware('permission:kelola_data.router.delete')->name('router.destroy');
        Route::post('/router/{router}/test', [RouterController::class, 'test'])->middleware(['permission:kelola_data.router.view', 'throttle:mikrotik'])->name('router.test');
    });

    // Menu global (tidak terikat cabang): hanya untuk user semua cabang
    Route::middleware('semua_cabang')->group(function () {
        // Template script isolir (Static/PPPoE/Hotspot) + isi halaman isolir
        Route::get('/kelola-data/template-isolir', [IsolirController::class, 'index'])->middleware('permission:kelola_data.template_isolir.view')->name('kelola-data.template-isolir');
        Route::put('/isolir/pengaturan', [IsolirController::class, 'simpanPengaturan'])->middleware('permission:kelola_data.template_isolir.update')->name('isolir.pengaturan');
        Route::post('/isolir/script', [IsolirController::class, 'script'])->middleware('permission:kelola_data.template_isolir.view')->name('isolir.script');

        // Tunnel memakai database + FreeRADIUS
        Route::get('/kelola-data/tunnel', [TunnelController::class, 'index'])->middleware('permission:kelola_data.tunnel.view')->name('kelola-data.tunnel');
        Route::post('/mikrotik/tunnel', [TunnelController::class, 'store'])->middleware('permission:kelola_data.tunnel.create')->name('mikrotik.tunnel.store');
        Route::put('/mikrotik/tunnel/{tunnel}', [TunnelController::class, 'update'])->middleware('permission:kelola_data.tunnel.update')->name('mikrotik.tunnel.update');
        Route::delete('/mikrotik/tunnel/{tunnel}', [TunnelController::class, 'destroy'])->middleware('permission:kelola_data.tunnel.delete')->name('mikrotik.tunnel.destroy');
        // POST: password user API ikut dikirim, jangan sampai tercatat di URL/log. Script memuat password tunnel → butuh izin ubah
        Route::post('/mikrotik/tunnel/{tunnel}/script', [TunnelController::class, 'script'])->middleware('permission:kelola_data.tunnel.update')->name('mikrotik.tunnel.script');

        // Akun user (role & cabang) dan matriks hak akses per role
        Route::get('/user/manajemen', [UserController::class, 'index'])->middleware('permission:user.manajemen.view')->name('user.manajemen');
        Route::post('/user', [UserController::class, 'store'])->middleware('permission:user.manajemen.create')->name('user.store');
        Route::put('/user/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware('permission:user.manajemen.update')->name('user.update');
        Route::put('/user/{user}/status', [UserController::class, 'status'])->whereNumber('user')->middleware('permission:user.manajemen.update')->name('user.status');

        Route::get('/user/hak-akses', [HakAksesController::class, 'index'])->middleware('permission:user.hak_akses.view')->name('user.hak-akses');
        Route::post('/user/role', [HakAksesController::class, 'store'])->middleware('permission:user.hak_akses.create')->name('role.store');
        Route::put('/user/role/{role}', [HakAksesController::class, 'update'])->middleware('permission:user.hak_akses.update')->name('role.update');
        Route::delete('/user/role/{role}', [HakAksesController::class, 'destroy'])->middleware('permission:user.hak_akses.delete')->name('role.destroy');
    });
});
