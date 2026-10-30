<?php

namespace App\Services\Tunnel;

use App\Models\Tunnel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Akun tunnel L2TP di tabel FreeRADIUS. Server L2TP (xl2tpd + pppd di VPS) memeriksa login ke FreeRADIUS,
 * lalu IP router di dalam tunnel dikirim sebagai Framed-IP-Address. Status online dibaca dari radacct.
 */
class TunnelRadius
{
    /** Interval accounting (detik) yang diminta ke pppd agar data sesi tetap segar. */
    private const INTERIM_DETIK = 60;

    /** Tulis ulang akun tunnel; $usernameLama dihapus bila username diganti. */
    public function simpan(Tunnel $tunnel, ?string $usernameLama = null): void
    {
        DB::transaction(function () use ($tunnel, $usernameLama) {
            $this->hapusAkun(array_filter([$usernameLama, $tunnel->username]));

            $check = [['username' => $tunnel->username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $tunnel->password]];
            if ($tunnel->status !== 'aktif') {
                // Tunnel dinonaktifkan: akun tetap ada tetapi login ditolak
                $check[] = ['username' => $tunnel->username, 'attribute' => 'Auth-Type', 'op' => ':=', 'value' => 'Reject'];
            }
            DB::table('radcheck')->insert($check);
            DB::table('radreply')->insert([
                ['username' => $tunnel->username, 'attribute' => 'Framed-IP-Address', 'op' => ':=', 'value' => $tunnel->ip_tunnel],
                ['username' => $tunnel->username, 'attribute' => 'Acct-Interim-Interval', 'op' => ':=', 'value' => (string) self::INTERIM_DETIK],
            ]);
        });
    }

    public function hapus(Tunnel $tunnel): void
    {
        $this->hapusAkun([$tunnel->username]);
    }

    /** Username sudah dipakai akun RADIUS lain (mis. akun uji atau pelanggan PPPoE)? */
    public function dipakaiAkunLain(string $username, ?Tunnel $kecuali = null): bool
    {
        return $kecuali?->username !== $username && DB::table('radcheck')->where('username', $username)->exists();
    }

    /**
     * Sesi tunnel yang sedang tersambung (belum ada Accounting-Stop), per username.
     *
     * @param  array<int, string>  $usernames
     * @return Collection<string, object{username: string, framedipaddress: string, acctstarttime: ?string, callingstationid: string}>
     */
    public function sesiAktif(array $usernames): Collection
    {
        return DB::table('radacct')
            ->whereIn('username', $usernames)
            ->whereNull('acctstoptime')
            ->orderBy('acctstarttime')
            ->get(['username', 'framedipaddress', 'acctstarttime', 'callingstationid'])
            ->keyBy('username');
    }

    /** @param array<int, string> $usernames */
    private function hapusAkun(array $usernames): void
    {
        DB::table('radcheck')->whereIn('username', $usernames)->delete();
        DB::table('radreply')->whereIn('username', $usernames)->delete();
    }
}
