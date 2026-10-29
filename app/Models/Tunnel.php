<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nama_tunnel', 'protokol', 'server', 'username', 'password', 'ip_tunnel', 'status'])]
#[Hidden(['password'])]
class Tunnel extends Model
{
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
