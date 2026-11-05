<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pelanggan korporat & instansi pemerintah (menu Data Pelanggan). Berbeda dengan reseller & retail yang
 * disimpan sebagai cabang (Lokasi), pelanggan ini berlangganan langsung dan tidak terikat cabang.
 * Path file KTP/NPWP disembunyikan dari array agar lokasi file privat tidak terkirim ke browser.
 */
#[Table('pelanggan_instansi')]
#[Fillable(['tipe', 'nama', 'alamat', 'kapasitas', 'harga', 'metro', 'nik', 'npwp', 'file_ktp', 'file_npwp', 'tanggal_aktif', 'tanggal_berhenti', 'jns_tagihan', 'status', 'sales_id'])]
#[Hidden(['file_ktp', 'file_npwp'])]
class PelangganInstansi extends Model
{
    /** @var array<string, string> */
    public const TIPE = ['korporat' => 'Korporat', 'pemerintah' => 'Instansi Pemerintah'];

    /** Dokumen yang bisa diunggah: jenis => kolom path. */
    public const DOKUMEN = ['ktp' => 'file_ktp', 'npwp' => 'file_npwp'];

    /** Folder dokumen di disk privat "local". */
    public const FOLDER_DOKUMEN = 'dokumen-pelanggan';

    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'tanggal_aktif' => 'date:Y-m-d',
            'tanggal_berhenti' => 'date:Y-m-d',
        ];
    }

    /** Sales yang membawa pelanggan ini (user ber-role Sales). */
    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(PembayaranInstansi::class);
    }
}
