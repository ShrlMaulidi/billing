<?php

namespace App\Http\Middleware;

use App\Models\Lokasi;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user() ? [
                    'nama' => $request->user()->name,
                    'username' => $request->user()->username,
                    'role' => $request->user()->getRoleNames()->first(),
                    'super_admin' => $request->user()->isSuperAdmin(),
                    'id_lokasi' => $request->user()->id_lokasi,
                ] : null,
                // Untuk menyembunyikan menu & tombol; server tetap memeriksa permission di setiap route
                'permissions' => fn () => $request->user()?->getAllPermissions()->pluck('name') ?? [],
                'ip' => $request->ip(),
            ],
            // User cabang hanya melihat cabangnya sendiri
            'cabangOptions' => fn () => $request->user()
                ? Lokasi::when($request->user()->id_lokasi, fn ($q, $id) => $q->whereKey($id))
                    ->orderBy('nama_lokasi')->get(['id', 'nama_lokasi'])->map(fn ($l) => ['value' => $l->id, 'label' => $l->nama_lokasi])
                : [],
        ];
    }
}
