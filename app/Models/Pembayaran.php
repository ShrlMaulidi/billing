<?php

namespace App\Models;

use App\Support\Audit;
use App\Support\RekapCabang;
use App\Support\TercatatAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pembayaran tagihan satu periode (bulan) pelanggan. Kolom periode = periode pemakaian; jns_tagihan = jenis tagihan saat dibayar.
 * Kolom periode sengaja tanpa cast date: disimpan & dibandingkan sebagai teks Y-m-d (tanpa jam) di MySQL maupun SQLite.
 */
#[Table('pembayaran')]
#[Fillable([
    'no_invoice', 'pelanggan_id', 'id_lokasi', 'periode', 'jns_tagihan', 'nama_pelanggan', 'nama_paket', 'harga', 'is_prorate', 'diskon_tipe', 'diskon_nilai', 'diskon',
    'jml_bayar', 'metode', 'rekening_id', 'user_id', 'paid_at',
])]
class Pembayaran extends Model
{
    use TercatatAudit;

    /** Nama modul & jenis data di menu Logs (jejak audit). */
    public const MODUL_AUDIT = 'Pembayaran';

    public const JENIS_AUDIT = 'pembayaran';

    /** Nama metode untuk kwitansi (selaras METODE di resources/js/lib/format.js). */
    public const METODE = ['cash' => 'Cash', 'transfer' => 'Transfer Bank', 'midtrans' => 'Midtrans', 'tripay' => 'Tripay', 'xendit' => 'Xendit', 'pakar_digital' => 'Pakar Digital', 'moota' => 'Moota'];

    /** Kanal pembayaran Midtrans (payment_type) yang mudah dipahami pelanggan. */
    public const KANAL_MIDTRANS = [
        'bank_transfer' => 'Virtual Account', 'echannel' => 'Mandiri Bill', 'permata' => 'Virtual Account Permata', 'qris' => 'QRIS', 'gopay' => 'GoPay',
        'shopeepay' => 'ShopeePay', 'credit_card' => 'Kartu Kredit', 'cstore' => 'Gerai Retail', 'akulaku' => 'Akulaku', 'kredivo' => 'Kredivo',
    ];

    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'is_prorate' => 'boolean',
            'diskon_nilai' => 'float',
            'diskon' => 'integer',
            'jml_bayar' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class);
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Transaksi online yang mencatat pembayaran ini (metode midtrans / xendit / tripay). */
    public function transaksiMidtrans(): HasOne
    {
        return $this->hasOne(TransaksiMidtrans::class);
    }

    /** Dibayar lewat payment gateway (mis. Midtrans): dicatat otomatis oleh sistem, bukan diinput petugas. */
    public function lewatPaymentGateway(): bool
    {
        return in_array($this->metode, RekapCabang::PAYMENT_GATEWAY, true);
    }

    /** Nama petugas yang menginput, atau "Otomatis (Midtrans)" untuk pembayaran payment gateway. Muat relasi petugas lebih dulu. */
    public function namaPetugas(): ?string
    {
        return $this->petugas?->name ?? ($this->lewatPaymentGateway() ? 'Otomatis ('.(self::METODE[$this->metode] ?? $this->metode).')' : null);
    }

    /**
     * Metode bayar untuk kwitansi: "Cash", "Transfer Bank · BCA", atau "Midtrans · QRIS".
     * Muat relasi rekening (transfer) dan transaksiMidtrans (midtrans) lebih dulu.
     */
    public function keteranganMetode(): string
    {
        $nama = self::METODE[$this->metode] ?? str($this->metode)->replace('_', ' ')->title()->toString();
        $rincian = match ($this->metode) {
            'transfer' => $this->rekening?->nama_rekening,
            'midtrans', 'xendit', 'tripay' => TransaksiMidtrans::namaKanal($this->metode, $this->transaksiMidtrans?->payment_type),
            default => null,
        };

        return $rincian ? "{$nama} · {$rincian}" : $nama;
    }

    /** Periode pembayaran (Y-m-01): prabayar = bulan pemakaian, pascabayar = bulan berikutnya. */
    public function periodePembayaran(): string
    {
        return $this->jns_tagihan === 'pascabayar' ? Carbon::parse($this->periode)->addMonth()->format('Y-m-d') : $this->periode;
    }

    /** Rentang tahun yang bisa dipilih saat input pembayaran, dihitung dari tahun berjalan. */
    public const TAHUN_MUNDUR = 5;

    public const TAHUN_MAJU = 5;

    /** @return list<int> */
    public static function tahunBayar(): array
    {
        return range(today()->year - self::TAHUN_MUNDUR, today()->year + self::TAHUN_MAJU);
    }

    /** Potongan dalam Rupiah (dibulatkan) dari diskon nominal atau persentase. */
    public static function hitungDiskon(int $harga, ?string $tipe, float $nilai): int
    {
        return match ($tipe) {
            'persentase' => (int) round($harga * $nilai / 100),
            'nominal' => (int) round($nilai),
            default => 0,
        };
    }

    /** Label di deskripsi Logs. */
    /**
     * Simpan pembayaran satu periode beserta snapshot nama, paket, dan jenis tagihan; no. invoice dibentuk dari periode + id.
     * Dipakai input manual, pembayaran massal, dan Midtrans. Tercatat sekali di Logs (sudah lengkap dengan no. invoice).
     *
     * @param  array{periode: string, harga: int, metode: string, rekening_id: ?int, diskon_tipe: ?string, diskon_nilai: mixed, diskon: int, jml_bayar: int}  $data
     *
     * @throws UniqueConstraintViolationException periode sudah dibayar (klik ganda / input bersamaan)
     */
    public static function catatUntuk(Pelanggan $pelanggan, array $data, ?int $userId, ?Carbon $dibayarPada = null): self
    {
        $pembayaran = DB::transaction(fn () => Audit::diam(function () use ($pelanggan, $data, $userId, $dibayarPada) {
            $pembayaran = $pelanggan->pembayaran()->create([
                ...$data,
                'id_lokasi' => $pelanggan->id_lokasi,
                'nama_pelanggan' => $pelanggan->nama,
                'nama_paket' => $pelanggan->paket->nama_paket,
                'jns_tagihan' => $pelanggan->jns_tagihan,
                'is_prorate' => $data['periode'] === $pelanggan->periodeProrate(),
                'user_id' => $userId,
                'paid_at' => $dibayarPada ?? now(),
            ]);
            $pembayaran->update(['no_invoice' => sprintf('INV%s-%05d', str_replace('-', '', substr($data['periode'], 0, 7)), $pembayaran->id)]);

            return $pembayaran;
        }));
        $pembayaran->catatAudit('create');

        return $pembayaran;
    }

    public function labelAudit(): string
    {
        return trim(($this->no_invoice ?? '#'.$this->getKey()).' · '.$this->nama_pelanggan);
    }
}
