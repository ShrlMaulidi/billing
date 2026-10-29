<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['router_id', 'pelanggan_id', 'username', 'aksi', 'profile_dari', 'profile_ke', 'berhasil', 'pesan'])]
class IsolirLog extends Model
{
    protected function casts(): array
    {
        return [
            'berhasil' => 'boolean',
        ];
    }
}
