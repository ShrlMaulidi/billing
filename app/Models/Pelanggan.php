<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pelanggan. Akun koneksi di router: username_pppoe untuk PPPoE & Hotspot, ip_address untuk Static,
 * mac_address untuk IP Binding.
 */
#[Table('pelanggan')]
#[Fillable([
    'id_pelanggan', 'nama', 'tlp', 'nik', 'alamat', 'jns_tagihan', 'skema_tagihan', 'id_lokasi', 'id_area', 'id_paket', 'router_id', 'jns_koneksi',
    'username_pppoe', 'ip_address', 'mac_address', 'tgl_registrasi', 'tgl_jatuh_tempo', 'ppn', 'sales_id',
    'status', 'status_langganan', 'profile_asal', 'isolir_at',
])]
class Pelanggan extends Model
{
    public const KONEKSI = ['pppoe' => 'PPPoE', 'hotspot' => 'Hotspot', 'static' => 'Static', 'ip_binding' => 'IP Binding'];

    public const SKEMA_TAGIHAN = ['full' => 'Tagihan Full', 'prorate' => 'Prorate'];

    /** Jatuh tempo tanggal 1–27 agar selalu ada di setiap bulan, termasuk Februari. */
    public const JATUH_TEMPO_MAKS = 27;

    protected function casts(): array
    {
        return [
            'tgl_registrasi' => 'date:Y-m-d',
            'tgl_jatuh_tempo' => 'integer',
            'ppn' => 'boolean',
            'isolir_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function paket(): BelongsTo
    {
        return $this->belongsTo(Paket::class, 'id_paket');
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'id_area');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class);
    }

    /** Pelanggan aktif yang sudah membayar tagihan yang muncul di bulan tagihan (Y-m-01). */
    #[Scope]
    protected function lunas(Builder $query, string $bulanTagihan): void
    {
        $this->perJenisTagihan($query, $bulanTagihan, fn ($q, $pemakaian) => $q->whereHas('pembayaran', fn ($p) => $p->where('periode', $pemakaian)));
    }

    /** Pelanggan aktif berpaket berbayar yang tagihannya sudah muncul di bulan tagihan tetapi belum dibayar. */
    #[Scope]
    protected function belumLunas(Builder $query, string $bulanTagihan): void
    {
        $query->whereHas('paket', fn ($q) => $q->where('harga', '>', 0));
        $this->perJenisTagihan($query, $bulanTagihan, fn ($q, $pemakaian) => $q
            ->where(fn ($q) => $q->whereNull('tgl_registrasi')->orWhere('tgl_registrasi', '<', Carbon::parse($pemakaian)->addMonth()->toDateString()))
            ->whereDoesntHave('pembayaran', fn ($p) => $p->where('periode', $pemakaian)));
    }

    /**
     * Prabayar ditagih untuk pemakaian bulan itu, pascabayar untuk pemakaian bulan sebelumnya.
     *
     * @param  callable(Builder, string): mixed  $kondisi  menerima query & periode pemakaian
     */
    private function perJenisTagihan(Builder $query, string $bulanTagihan, callable $kondisi): void
    {
        $query->where('status', 'aktif')->where(fn ($q) => $q
            ->where(fn ($q) => $kondisi($q->where('jns_tagihan', '!=', 'pascabayar'), $bulanTagihan))
            ->orWhere(fn ($q) => $kondisi($q->where('jns_tagihan', 'pascabayar'), Carbon::parse($bulanTagihan)->subMonth()->format('Y-m-d'))));
    }

    /** Periode pemakaian (Y-m-01) yang ditagih pada bulan tagihan sesuai jenis tagihan pelanggan. */
    public function periodePemakaian(string $bulanTagihan): string
    {
        return $this->jns_tagihan === 'pascabayar' ? Carbon::parse($bulanTagihan)->subMonth()->format('Y-m-d') : $bulanTagihan;
    }

    /** Jumlah hari prorate (tanggal registrasi s/d akhir bulan, inklusif); null bila skema full atau registrasi tanggal 1. */
    public function hariProrate(): ?int
    {
        if ($this->skema_tagihan !== 'prorate' || ! $this->tgl_registrasi || $this->tgl_registrasi->day === 1) {
            return null;
        }

        return $this->tgl_registrasi->daysInMonth - $this->tgl_registrasi->day + 1;
    }

    /** Periode pemakaian yang ditagih prorate (bulan registrasi), null bila tidak ada prorate. */
    public function periodeProrate(): ?string
    {
        return $this->hariProrate() ? $this->tgl_registrasi->format('Y-m-01') : null;
    }

    /** Tanggal tagihan prorate muncul: prabayar saat registrasi, pascabayar tanggal 1 bulan berikutnya. */
    public function prorateMuncul(): ?string
    {
        if (! $this->periodeProrate()) {
            return null;
        }

        return $this->jns_tagihan === 'pascabayar'
            ? $this->tgl_registrasi->copy()->startOfMonth()->addMonth()->toDateString()
            : $this->tgl_registrasi->toDateString();
    }

    /**
     * Nominal tagihan satu periode pemakaian: prorate (dibulatkan ke ribuan ke atas, tidak melebihi harga paket)
     * untuk bulan registrasi pelanggan berskema prorate, selain itu harga paket. Muat relasi paket lebih dulu.
     */
    public function tagihanUntuk(string $periode): ?int
    {
        $harga = $this->paket?->harga;
        $hari = $this->hariProrate();
        if ($harga === null || ! $hari || $periode !== $this->periodeProrate()) {
            return $harga;
        }

        return (int) min($harga, ceil($harga * $hari / $this->tgl_registrasi->daysInMonth / 1000) * 1000);
    }

    /** Tagihan prorate sudah muncul tetapi belum dibayar. Muat relasi pembayaran lebih dulu. */
    public function prorateBelumDibayar(): bool
    {
        $periode = $this->periodeProrate();

        return $periode !== null && $this->status === 'aktif' && $this->prorateMuncul() <= today()->toDateString()
            && ! $this->pembayaran->contains('periode', $periode);
    }

    /** Identitas akun di router sesuai jenis koneksi (username, IP, atau MAC). */
    public function akunKoneksi(): ?string
    {
        return match ($this->jns_koneksi) {
            'static' => $this->ip_address,
            'ip_binding' => $this->mac_address,
            default => $this->username_pppoe,
        };
    }

    /**
     * Baris tabel pelanggan di halaman. Muat relasi lokasi & paket lebih dulu agar tidak N+1.
     *
     * @return array<string, mixed>
     */
    public function toBaris(): array
    {
        return [
            ...$this->toArray(),
            'cabang' => $this->lokasi?->nama_lokasi ?? '-',
            'paket' => $this->paket?->nama_paket ?? '-',
            'harga' => $this->paket?->harga,
        ];
    }
}
