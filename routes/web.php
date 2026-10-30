<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KelolaDataController;
use App\Http\Controllers\PaketController;
use App\Http\Controllers\PelangganController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\SistemController;
use App\Http\Controllers\TransaksiController;
use App\Http\Controllers\TunnelController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

// Semua halaman aplikasi & aksi Mikrotik wajib login
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::controller(DashboardController::class)->group(function () {
        Route::get('/dashboard', 'index')->name('dashboard');
        Route::get('/dashboard/pelanggan', 'pelanggan')->name('dashboard.pelanggan');
        Route::get('/dashboard-cabang', 'cabang')->name('dashboard-cabang');
        Route::get('/dashboard-cabang/{id}', 'cabangDetail')->name('dashboard-cabang.detail');
        Route::get('/income-summary', 'income')->name('income-summary');
    });

    Route::prefix('kelola-data')->name('kelola-data.')->controller(KelolaDataController::class)->group(function () {
        Route::get('/kartu-member', 'kartuMember')->name('kartu-member');
        Route::get('/cabang', 'cabang')->name('cabang');
        Route::get('/area', 'area')->name('area');
        Route::get('/pajak', 'pajak')->name('pajak');
        Route::get('/rekening', 'rekening')->name('rekening');
        Route::get('/notifikasi', 'notifikasi')->name('notifikasi');
        Route::get('/pph', 'pph')->name('pph');
    });

    // Data pelanggan (database) — On/Off internet lewat isolir PPPoE
    Route::get('/pelanggan', [PelangganController::class, 'index'])->name('pelanggan.index');
    Route::get('/pelanggan/on', [PelangganController::class, 'index'])->defaults('status', 'on')->name('pelanggan.on');
    Route::get('/pelanggan/off', [PelangganController::class, 'index'])->defaults('status', 'off')->name('pelanggan.off');
    Route::post('/pelanggan', [PelangganController::class, 'store'])->name('pelanggan.store');
    Route::put('/pelanggan/{pelanggan}', [PelangganController::class, 'update'])->name('pelanggan.update');
    Route::delete('/pelanggan/{pelanggan}', [PelangganController::class, 'destroy'])->name('pelanggan.destroy');
    Route::post('/pelanggan/{pelanggan}/isolir', [PelangganController::class, 'isolir'])->name('pelanggan.isolir');
    Route::post('/pelanggan/{pelanggan}/aktifkan', [PelangganController::class, 'aktifkan'])->name('pelanggan.aktifkan');

    // Paket langganan (database) — profile paket bisa disinkronkan ke Mikrotik
    Route::get('/kelola-data/paket', [PaketController::class, 'index'])->name('kelola-data.paket');
    Route::post('/paket', [PaketController::class, 'store'])->name('paket.store');
    Route::put('/paket/{paket}', [PaketController::class, 'update'])->name('paket.update');
    Route::delete('/paket/{paket}', [PaketController::class, 'destroy'])->name('paket.destroy');
    Route::post('/paket/{paket}/sinkron', [PaketController::class, 'sinkron'])->name('paket.sinkron');

    // Router & tunnel memakai database + koneksi Mikrotik sungguhan
    Route::get('/kelola-data/router', [RouterController::class, 'index'])->name('kelola-data.router');
    Route::get('/kelola-data/tunnel', [TunnelController::class, 'index'])->name('kelola-data.tunnel');

    Route::prefix('mikrotik')->name('mikrotik.')->group(function () {
        Route::post('/router', [RouterController::class, 'store'])->name('router.store');
        Route::put('/router/{router}', [RouterController::class, 'update'])->name('router.update');
        Route::delete('/router/{router}', [RouterController::class, 'destroy'])->name('router.destroy');
        Route::post('/router/{router}/test', [RouterController::class, 'test'])->name('router.test');
        Route::get('/router/{router}/profiles', [RouterController::class, 'profiles'])->name('router.profiles');

        Route::post('/tunnel', [TunnelController::class, 'store'])->name('tunnel.store');
        Route::put('/tunnel/{tunnel}', [TunnelController::class, 'update'])->name('tunnel.update');
        Route::delete('/tunnel/{tunnel}', [TunnelController::class, 'destroy'])->name('tunnel.destroy');
        Route::get('/tunnel/{tunnel}/script', [TunnelController::class, 'script'])->name('tunnel.script');

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
        Route::get('/user/manajemen', 'users')->name('user.manajemen');
        Route::get('/user/hak-akses', 'hakAkses')->name('user.hak-akses');
        Route::get('/profil', 'profil')->name('profil');
    });
});
