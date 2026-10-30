<?php

use App\Http\Middleware\EnsureUserCoversAllBranches;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\HakAkses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'semua_cabang' => EnsureUserCoversAllBranches::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        // User yang sudah login diarahkan ke menu pertama yang boleh dibukanya
        $middleware->redirectUsersTo(fn (Request $request) => HakAkses::halamanAwal($request->user()) ?? '/profil');
        // Server di balik NAT: HTTPS ditangani proxy (Cloudflare Tunnel di localhost / reverse proxy di jaringan lokal).
        // Hanya alamat lokal/private yang dipercaya, agar header X-Forwarded-* dari internet tidak bisa dipalsukan.
        $middleware->trustProxies(at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Tolakan role/permission spatie tampil sebagai 403 berbahasa Indonesia (tanpa membocorkan nama permission)
        $exceptions->map(UnauthorizedException::class, fn () => new AccessDeniedHttpException('Anda tidak memiliki akses ke fitur ini'));
    })->create();
