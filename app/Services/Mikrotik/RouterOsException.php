<?php

namespace App\Services\Mikrotik;

use RuntimeException;

/**
 * Kesalahan saat berkomunikasi dengan Mikrotik.
 * jenis: koneksi (tidak terjangkau / timeout) | login (user/password salah) | perintah (ditolak router).
 */
class RouterOsException extends RuntimeException
{
    public function __construct(string $message, public readonly string $jenis = 'perintah')
    {
        parent::__construct($message);
    }

    public static function koneksi(string $message): self
    {
        return new self($message, 'koneksi');
    }

    public static function login(string $message): self
    {
        return new self($message, 'login');
    }
}
