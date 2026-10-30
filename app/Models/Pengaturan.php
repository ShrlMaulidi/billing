<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('pengaturan')]
#[Fillable(['kunci', 'nilai'])]
class Pengaturan extends Model
{
    protected $primaryKey = 'kunci';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Nilai tersimpan untuk kunci-kunci $default; kunci yang belum pernah disimpan memakai nilai default.
     *
     * @param  array<string, ?string>  $default
     * @return array<string, ?string>
     */
    public static function ambil(array $default): array
    {
        return array_merge($default, static::whereIn('kunci', array_keys($default))->pluck('nilai', 'kunci')->all());
    }

    /** @param array<string, ?string> $nilai */
    public static function simpanSemua(array $nilai): void
    {
        foreach ($nilai as $kunci => $isi) {
            static::updateOrCreate(['kunci' => $kunci], ['nilai' => $isi]);
        }
    }
}
