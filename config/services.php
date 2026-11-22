<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mikrotik' => [
        // Batas waktu (detik) koneksi & balasan RouterOS API
        'timeout' => env('MIKROTIK_TIMEOUT', 5),
    ],

    'tunnel' => [
        // Server L2TP (xl2tpd di VPS) yang di-dial router turunan; default = host APP_URL
        'server' => env('TUNNEL_SERVER', parse_url((string) env('APP_URL'), PHP_URL_HOST)),
        // Jaringan di dalam tunnel: gateway = IP VPS, sisanya untuk router turunan
        'subnet' => env('TUNNEL_SUBNET', '10.10.10.0/24'),
        'gateway' => env('TUNNEL_GATEWAY', '10.10.10.1'),
    ],

    'isolir' => [
        // Tujuan redirect pelanggan terisolir; kosong = APP_URL/isolir
        'url' => env('ISOLIR_URL'),
    ],

    // Payment gateway perusahaan (pelanggan cabang non Admin Full). Key hanya di .env, tidak pernah dikirim ke browser.
    // Pengaturan umum pembayaran online semua provider (Midtrans, Xendit, Tripay)
    'pembayaran' => [
        'timeout' => (int) env('PEMBAYARAN_TIMEOUT', 15),
        // Tripay mewajibkan email pelanggan; pelanggan tidak menyimpan email, jadi memakai alamat ini
        'email_pelanggan' => env('PEMBAYARAN_EMAIL_PELANGGAN', 'noreply@mediasolusisukses.co.id'),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        // Dashboard Midtrans baru: notifikasi bisa ditandatangani dengan Signature Secret Key (opsional, selain Server Key)
        'signature_key' => env('MIDTRANS_SIGNATURE_KEY'),
        'production' => (bool) env('MIDTRANS_PRODUCTION', false),
        // Awalan order ID agar tidak bentrok dengan website lain yang memakai akun Midtrans yang sama
        'prefix' => env('MIDTRANS_ORDER_PREFIX', 'MSSB'),
        'kedaluwarsa_jam' => (int) env('MIDTRANS_KEDALUWARSA_JAM', 24),
        'timeout' => (int) env('MIDTRANS_TIMEOUT', 15),
    ],

];
