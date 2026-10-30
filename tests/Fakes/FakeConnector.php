<?php

namespace Tests\Fakes;

use App\Models\Router;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikGateway;
use App\Services\Mikrotik\RouterOsException;

/** Connector tiruan: selalu memberi FakeGateway, atau melempar $gagal bila diisi. */
class FakeConnector extends MikrotikConnector
{
    public ?RouterOsException $gagal = null;

    public function __construct(private FakeGateway $gw) {}

    public function connect(Router $router): MikrotikGateway
    {
        return $this->gagal ? throw $this->gagal : $this->gw;
    }
}
