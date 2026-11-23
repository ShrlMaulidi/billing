<?php

namespace App\Models;

use App\Support\TercatatAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kalimat pesan WhatsApp milik satu cabang (menu Notifikasi). Cabang yang belum mengatur template memakai BAWAAN.
 * Placeholder diganti saat pesan dibuat (App\Support\PesanTagihan).
 */
#[Table('template_pesan')]
#[Fillable(['id_lokasi', 'jenis', 'isi'])]
class TemplatePesan extends Model
{
    use TercatatAudit;

    /** Nama modul & jenis data di menu Logs (jejak audit). */
    public const MODUL_AUDIT = 'Notifikasi';

    public const JENIS_AUDIT = 'template_pesan';

    public const MAKS_ISI = 2000;

    /** @var array<string, string> placeholder => keterangan */
    public const PLACEHOLDER = [
        '{nama}' => 'Nama pelanggan',
        '{id_pelanggan}' => 'ID pelanggan',
        '{paket}' => 'Nama paket',
        '{periode}' => 'Bulan tagihan, mis. November 2026',
        '{tagihan}' => 'Nominal tagihan, mis. Rp 200.000',
        '{jatuh_tempo}' => 'Tanggal jatuh tempo',
        '{link}' => 'Link cek tagihan & bayar online',
        '{cabang}' => 'Nama cabang',
    ];

    public const BAWAAN = "Halo {nama},\n\nTagihan internet {paket} untuk periode *{periode}* sebesar *{tagihan}* jatuh tempo pada {jatuh_tempo}.\n\nCek tagihan & bayar online:\n{link}\n\nAbaikan pesan ini bila sudah membayar. Terima kasih.\n— {cabang}";

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'id_lokasi');
    }

    public function labelAudit(): string
    {
        return 'Template '.$this->jenis.' '.($this->lokasi?->nama_lokasi ?? '#'.$this->id_lokasi);
    }

    /** Isi template cabang, atau kalimat bawaan bila cabang belum mengaturnya. */
    public static function isiUntuk(int $idLokasi, string $jenis = 'tagihan'): string
    {
        return static::where(['id_lokasi' => $idLokasi, 'jenis' => $jenis])->value('isi') ?? self::BAWAAN;
    }
}
