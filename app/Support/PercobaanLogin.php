<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pembatas percobaan login gagal, dipakai login web dan login API agar keduanya berbagi hitungan yang sama.
 * Dikunci per kombinasi username + IP, dan per IP untuk username apa pun (mencegah menebak banyak akun sekaligus).
 */
class PercobaanLogin
{
    /** Maksimal percobaan gagal untuk kombinasi username + IP, lalu dikunci selama JEDA_DETIK. */
    private const MAX_PERCOBAAN = 5;

    private const JEDA_DETIK = 300;

    /** Maksimal percobaan gagal dari satu IP untuk username apa pun. */
    private const MAX_PERCOBAAN_IP = 20;

    private const JEDA_IP_DETIK = 900;

    private string $kunci;

    private string $kunciIp;

    public function __construct(string $username, string $ip, private string $field = 'username')
    {
        $this->kunci = Str::lower($username).'|'.$ip;
        $this->kunciIp = 'login-ip|'.$ip;
    }

    /** @throws ValidationException bila username + IP atau IP ini sedang dikunci */
    public function pastikanBelumTerkunci(): void
    {
        foreach ([$this->kunci => self::MAX_PERCOBAAN, $this->kunciIp => self::MAX_PERCOBAAN_IP] as $kunci => $maks) {
            if (RateLimiter::tooManyAttempts($kunci, $maks)) {
                throw ValidationException::withMessages([
                    $this->field => 'Terlalu banyak percobaan login. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.',
                ]);
            }
        }
    }

    public function gagal(): void
    {
        RateLimiter::hit($this->kunci, self::JEDA_DETIK);
        RateLimiter::hit($this->kunciIp, self::JEDA_IP_DETIK);
    }

    public function berhasil(): void
    {
        RateLimiter::clear($this->kunci);
    }
}
