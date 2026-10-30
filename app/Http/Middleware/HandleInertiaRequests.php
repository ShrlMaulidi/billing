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
                    'role' => $request->user()->role,
                ] : null,
                'ip' => $request->ip(),
            ],
            'cabangOptions' => fn () => $request->user()
                ? Lokasi::orderBy('nama_lokasi')->get(['id', 'nama_lokasi'])->map(fn ($l) => ['value' => $l->id, 'label' => $l->nama_lokasi])
                : [],
        ];
    }
}
