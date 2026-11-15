<?php

namespace App\Models;

use App\Support\TercatatAudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nama_tunnel', 'protokol', 'server', 'username', 'password', 'ip_tunnel', 'status'])]
#[Hidden(['password'])]
class Tunnel extends Model
{
    use TercatatAudit;

    /** Nama modul & jenis data di menu Logs (jejak audit). */
    public const MODUL_AUDIT = 'Tunnel';

    public const JENIS_AUDIT = 'tunnel';

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public function routers(): HasMany
    {
        return $this->hasMany(Router::class);
    }
}
