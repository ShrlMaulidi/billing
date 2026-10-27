<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KelolaDataController;
use App\Http\Controllers\SistemController;
use App\Http\Controllers\TransaksiController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard');
Route::get('/login', fn () => Inertia::render('Auth/Login'))->name('login');

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
    Route::get('/pajak', 'pajak')->name('pajak');
    Route::get('/paket', 'paket')->name('paket');
    Route::get('/router', 'router')->name('router');
    Route::get('/rekening', 'rekening')->name('rekening');
    Route::get('/notifikasi', 'notifikasi')->name('notifikasi');
    Route::get('/pph', 'pph')->name('pph');
    Route::get('/tunnel', 'tunnel')->name('tunnel');
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
