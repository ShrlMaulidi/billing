<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\OpsiController;
use App\Http\Controllers\Api\V1\PelangganController as ApiPelangganController;
use App\Http\Controllers\Api\V1\PembayaranController as ApiPembayaranController;
use App\Http\Controllers\Api\V1\PenarikanSaldoController;
use App\Http\Controllers\Api\V1\PortalPelangganController;
use App\Http\Controllers\Api\V1\RekapCabangController;
use App\Http\Controllers\Api\V1\SetoranMitraController;
use App\Http\Controllers\PelangganController;
use App\Http\Controllers\PembayaranController;
use App\Http\Controllers\TransaksiController;
use Illuminate\Support\Facades\Route;

/*
 * API aplikasi mobile (Android/Flutter), prefix /api/v1. Login → token Sanctum, kirim sebagai "Authorization: Bearer <token>"
 * dengan header "Accept: application/json".
 * - Staf: semua akun yang bisa login web. Permission spatie & batasan cabang sama persis dengan route web; aksi yang di web
 *   sudah mengembalikan JSON memakai controller web yang sama.
 * - Pelanggan: login ID pelanggan + nomor HP, hanya data miliknya sendiri.
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('login');
    Route::post('/pelanggan/login', [PortalPelangganController::class, 'login'])->middleware('throttle:api-login')->name('portal.login');

    Route::middleware(['auth:sanctum', 'ability:staf', 'token_aktif'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::get('/opsi', OpsiController::class)->name('opsi');

        Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
        Route::get('/rekap-cabang', RekapCabangController::class)->middleware('permission:dashboard_cabang.view')->name('rekap-cabang');

        // Daftar: permission diperiksa di controller sesuai filter status (tab Pelanggan web)
        Route::get('/pelanggan', [ApiPelangganController::class, 'index'])->name('pelanggan.index');
        Route::get('/pelanggan/{pelanggan}', [ApiPelangganController::class, 'show'])->whereNumber('pelanggan')
            ->middleware('permission:pelanggan.data.view|pelanggan.on.view|pelanggan.off.view|pelanggan.lunas.view|pelanggan.belum_lunas.view|pelanggan.berhenti.view|pelanggan.prorate.view')
            ->name('pelanggan.show');
        Route::get('/pelanggan/{pelanggan}/tagihan', [ApiPelangganController::class, 'tagihan'])->middleware('permission:pelanggan.belum_lunas.view|pelanggan.prorate.view')->name('pelanggan.tagihan');
        Route::post('/pelanggan', [PelangganController::class, 'store'])->middleware('permission:pelanggan.data.create')->name('pelanggan.store');
        Route::put('/pelanggan/{pelanggan}', [PelangganController::class, 'update'])->middleware('permission:pelanggan.data.update')->name('pelanggan.update');
        Route::delete('/pelanggan/{pelanggan}', [PelangganController::class, 'destroy'])->middleware('permission:pelanggan.data.delete')->name('pelanggan.destroy');

        Route::post('/pelanggan/{pelanggan}/bayar', [PembayaranController::class, 'store'])->middleware('permission:pelanggan.pembayaran.create')->name('pelanggan.bayar');
        Route::post('/pembayaran/massal', [PembayaranController::class, 'massal'])->middleware('permission:pelanggan.pembayaran.create')->name('pembayaran.massal');
        Route::get('/pelanggan/{pelanggan}/pembayaran', [PembayaranController::class, 'index'])->middleware('permission:pelanggan.pembayaran.view')->name('pelanggan.pembayaran');
        Route::get('/pembayaran/{pembayaran}', [ApiPembayaranController::class, 'show'])->middleware('permission:pelanggan.pembayaran.view')->name('pembayaran.show');
        Route::put('/pembayaran/{pembayaran}', [PembayaranController::class, 'update'])->middleware('permission:pelanggan.pembayaran.update')->name('pembayaran.update');
        Route::delete('/pembayaran/{pembayaran}', [PembayaranController::class, 'destroy'])->middleware('permission:pelanggan.pembayaran.delete')->name('pembayaran.destroy');

        // Aksi yang langsung menghubungi router: batas frekuensi sama dengan web
        Route::middleware('throttle:mikrotik')->group(function () {
            Route::post('/pelanggan/{pelanggan}/isolir', [PelangganController::class, 'isolir'])->middleware('permission:pelanggan.on.update')->name('pelanggan.isolir');
            Route::post('/pelanggan/{pelanggan}/aktifkan', [PelangganController::class, 'aktifkan'])->middleware('permission:pelanggan.off.update')->name('pelanggan.aktifkan');
            Route::get('/router/{router}/akun', [PelangganController::class, 'akunRouter'])->middleware('permission:pelanggan.data.create|pelanggan.data.update')->name('router.akun');
            Route::get('/router/{router}/ip', [PelangganController::class, 'ipRouter'])->middleware('permission:pelanggan.data.create|pelanggan.data.update')->name('router.ip');
        });

        Route::prefix('transaksi')->name('transaksi.')->group(function () {
            Route::get('/penarikan-saldo', [PenarikanSaldoController::class, 'index'])->middleware('permission:transaksi.penarikan.view')->name('penarikan.index');
            Route::post('/penarikan-saldo', [TransaksiController::class, 'ajukanPenarikan'])->middleware('permission:transaksi.penarikan.create')->name('penarikan.store');
            Route::get('/penarikan-saldo/{penarikan}/bukti', [TransaksiController::class, 'buktiPenarikan'])->middleware('permission:transaksi.penarikan.view')->name('penarikan.bukti');
            Route::get('/setor-kewajiban', [SetoranMitraController::class, 'index'])->middleware('permission:transaksi.setor.view')->name('setor.index');
            Route::get('/setor-kewajiban/{setoran}/bukti', [TransaksiController::class, 'buktiSetoran'])->middleware('permission:transaksi.setor.view')->name('setor.bukti');

            // Hanya user semua cabang (sama dengan web)
            Route::middleware('semua_cabang')->group(function () {
                Route::post('/penarikan-saldo/{penarikan}/setujui', [TransaksiController::class, 'setujuiPenarikan'])->middleware('permission:transaksi.penarikan.approve')->name('penarikan.setujui');
                Route::post('/penarikan-saldo/{penarikan}/tolak', [TransaksiController::class, 'tolakPenarikan'])->middleware('permission:transaksi.penarikan.approve')->name('penarikan.tolak');
                Route::post('/setor-kewajiban', [TransaksiController::class, 'simpanSetoran'])->middleware('permission:transaksi.setor.create')->name('setor.store');
                Route::delete('/setor-kewajiban/{setoran}', [TransaksiController::class, 'hapusSetoran'])->middleware('permission:transaksi.setor.delete')->name('setor.destroy');
            });
        });
    });

    Route::prefix('pelanggan-saya')->name('portal.')->middleware(['auth:sanctum', 'ability:pelanggan', 'token_aktif'])
        ->controller(PortalPelangganController::class)->group(function () {
            Route::get('/', 'profil')->name('profil');
            Route::get('/tagihan', 'tagihan')->name('tagihan');
            Route::get('/pembayaran', 'pembayaran')->name('pembayaran');
            Route::post('/logout', 'logout')->name('logout');
        });
});
