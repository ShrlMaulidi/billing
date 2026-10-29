<?php

namespace App\Services\Mikrotik;

use App\Models\Router;

/**
 * Membuka koneksi API ke router sesuai mode koneksinya:
 * - langsung: host (IP publik / domain) + port API
 * - tunnel  : IP router di dalam tunnel + port API (untuk router turunan ber-IP private)
 */
class MikrotikConnector
{
    public function connect(Router $router): MikrotikGateway
    {
        if ($router->mode_koneksi === 'tunnel') {
            if (! $router->tunnel) {
                throw RouterOsException::koneksi('Router belum dihubungkan ke tunnel');
            }
            if ($router->tunnel->status !== 'aktif') {
                throw RouterOsException::koneksi("Tunnel {$router->tunnel->nama_tunnel} sedang tidak aktif");
            }
        }

        $host = $router->alamatApi();
        if (! $host) {
            throw RouterOsException::koneksi('Alamat router belum diisi');
        }

        $api = RouterOsApi::connect($host, $router->port_api, $router->ssl, (int) config('services.mikrotik.timeout', 5));
        $api->login($router->username, $router->password);

        return new RouterOsGateway($api);
    }
}
