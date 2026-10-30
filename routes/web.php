<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CabangController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IsolirController;
use App\Http\Controllers\KelolaDataController;
use App\Http\Controllers\PaketController;
use App\Http\Controllers\PelangganController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\SistemController;
use App\Http\Controllers\TransaksiController;
use App\Http\Controllers\TunnelController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
// Tujuan redirect web proxy router untuk pelanggan terisolir (tanpa login)
Route::get('/isolir', [IsolirController::class, 'halaman'])->name('isolir');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

// Semua halaman aplikasi & aksi Mikrotik wajib login sebagai staf (Super Admin / Admin)
Route::middleware('auth')->post('/logout', [AuthController::class, 'destroy'])->name('logout');

Route::middleware(['auth', 'role:'.implode(',', User::ROLE_STAF)])->group(function () {
    Route::controller(DashboardController::class)->group(function () {
        Route::get('/dashboard', 'index')->name('dashboard');
        Route::get('/dashboard/pelanggan', 'pelanggan')->name('dashboard.pelanggan');
        Route::get('/dashboard-cabang', 'cabang')->name('dashboard-cabang');
        Route::get('/dashboard-cabang/{id}', 'cabangDetail')->name('dashboard-cabang.detail');
        Route::get('/income-summary', 'income')->name('income-summary');
    });

    Route::prefix('kelola-data')->name('kelola-data.')->controller(KelolaDataController::class)->group(function () {
        Route::get('/kartu-member', 'kartuMember')->name('kartu-member');
        Route::get('/pajak', 'pajak')->name('pajak');
        Route::get('/rekening', 'rekening')->name('rekening');
        Route::get('/notifikasi', 'notifikasi')->name('notifikasi');
        Route::get('/pph', 'pph')->name('pph');
    });

    // Cabang & area (database)
    Route::get('/kelola-data/cabang', [CabangController::class, 'index'])->name('kelola-data.cabang');
    Route::post('/cabang', [CabangController::class, 'store'])->name('cabang.store');
    Route::put('/cabang/{lokasi}', [CabangController::class, 'update'])->name('cabang.update');
    Route::delete('/cabang/{lokasi}', [CabangController::class, 'destroy'])->name('cabang.destroy');
    Route::get('/kelola-data/area', [AreaController::class, 'index'])->name('kelola-data.area');
    Route::post('/area', [AreaController::class, 'store'])->name('area.store');
    Route::put('/area/{area}', [AreaController::class, 'update'])->name('area.update');
    Route::delete('/area/{area}', [AreaController::class, 'destroy'])->name('area.destroy');

    // Data pelanggan (database) — akun koneksi di Mikrotik & On/Off internet lewat isolir PPPoE
    Route::get('/pelanggan', [PelangganController::class, 'index'])->name('pelanggan.index');
    Route::get('/pelanggan/on', [PelangganController::class, 'index'])->defaults('status', 'on')->name('pelanggan.on');
    Route::get('/pelanggan/off', [PelangganController::class, 'index'])->defaults('status', 'off')->name('pelanggan.off');
    Route::post('/pelanggan', [PelangganController::class, 'store'])->name('pelanggan.store');
    Route::put('/pelanggan/{pelanggan}', [PelangganController::class, 'update'])->name('pelanggan.update');
    Route::delete('/pelanggan/{pelanggan}', [PelangganController::class, 'destroy'])->name('pelanggan.destroy');

    // Paket langganan (database) — profile paket bisa disinkronkan ke Mikrotik
    Route::get('/kelola-data/paket', [PaketController::class, 'index'])->name('kelola-data.paket');
    Route::post('/paket', [PaketController::class, 'store'])->name('paket.store');
    Route::put('/paket/{paket}', [PaketController::class, 'update'])->name('paket.update');
    Route::delete('/paket/{paket}', [PaketController::class, 'destroy'])->name('paket.destroy');

    // Aksi yang langsung menghubungi router dibatasi frekuensinya (lihat AppServiceProvider)
    Route::middleware('throttle:mikrotik')->group(function () {
        Route::post('/pelanggan/{pelanggan}/isolir', [PelangganController::class, 'isolir'])->name('pelanggan.isolir');
        Route::post('/pelanggan/{pelanggan}/aktifkan', [PelangganController::class, 'aktifkan'])->name('pelanggan.aktifkan');
        Route::get('/pelanggan/akun-router/{router}', [PelangganController::class, 'akunRouter'])->name('pelanggan.akun-router');
        Route::post('/paket/{paket}/sinkron', [PaketController::class, 'sinkron'])->name('paket.sinkron');
        Route::get('/mikrotik/router/{router}/profiles', [RouterController::class, 'profiles'])->name('mikrotik.router.profiles');
    });

    Route::prefix('transaksi')->name('transaksi.')->controller(TransaksiController::class)->group(function () {
        Route::get('/penarikan-saldo', 'penarikan')->name('penarikan');
        Route::get('/setor-kewajiban', 'setor')->name('setor');
        Route::get('/pengeluaran', 'pengeluaran')->name('pengeluaran');
    });

    Route::controller(SistemController::class)->group(function () {
        Route::get('/laporan', 'laporan')->name('laporan');
        Route::get('/payment-gateway', 'paymentGateway')->name('payment-gateway');
        Route::get('/payment-gateway/{id}/channel', 'pgChannel')->name('payment-gateway.channel');
        Route::get('/logs', 'logs')->name('logs');
        Route::get('/profil', 'profil')->name('profil');
    });

    /*
     * Khusus Super Admin: kredensial router & tunnel, script yang di-paste ke router, dan manajemen user.
     * Menu ini bisa mengubah konfigurasi Mikrotik secara langsung, jadi tidak dibuka untuk Admin.
     */
    Route::middleware('role:'.User::SUPER_ADMIN)->group(function () {
        // Template script isolir (Static/PPPoE/Hotspot) + isi halaman isolir
        Route::get('/kelola-data/template-isolir', [IsolirController::class, 'index'])->name('kelola-data.template-isolir');
        Route::put('/isolir/pengaturan', [IsolirController::class, 'simpanPengaturan'])->name('isolir.pengaturan');
        Route::post('/isolir/script', [IsolirController::class, 'script'])->name('isolir.script');

        // Router & tunnel memakai database + koneksi Mikrotik sungguhan
        Route::get('/kelola-data/router', [RouterController::class, 'index'])->name('kelola-data.router');
        Route::get('/kelola-data/tunnel', [TunnelController::class, 'index'])->name('kelola-data.tunnel');

        Route::prefix('mikrotik')->name('mikrotik.')->group(function () {
            Route::post('/router', [RouterController::class, 'store'])->name('router.store');
            Route::put('/router/{router}', [RouterController::class, 'update'])->name('router.update');
            Route::delete('/router/{router}', [RouterController::class, 'destroy'])->name('router.destroy');
            Route::post('/router/{router}/test', [RouterController::class, 'test'])->middleware('throttle:mikrotik')->name('router.test');

            Route::post('/tunnel', [TunnelController::class, 'store'])->name('tunnel.store');
            Route::put('/tunnel/{tunnel}', [TunnelController::class, 'update'])->name('tunnel.update');
            Route::delete('/tunnel/{tunnel}', [TunnelController::class, 'destroy'])->name('tunnel.destroy');
            // POST: password user API ikut dikirim, jangan sampai tercatat di URL/log
            Route::post('/tunnel/{tunnel}/script', [TunnelController::class, 'script'])->name('tunnel.script');
        });

        Route::get('/user/manajemen', [SistemController::class, 'users'])->name('user.manajemen');
        Route::get('/user/hak-akses', [SistemController::class, 'hakAkses'])->name('user.hak-akses');
    });
});
