<?php

namespace Cipriani\GatewayMailer\Transport;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

class GatewayTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $store = $dsn->getOption('cache_store') ?? config('gateway-mailer.cache.store');
        $cacheKey = $dsn->getOption('cache_key') ?? config('gateway-mailer.cache.key', 'gateway-mailer.token');

        return new GatewayTransport(
            new Client(),
            $dsn->getOption('base_url') ?? config('gateway-mailer.base_url'),
            $dsn->getUser() ?? $dsn->getOption('client_id') ?? config('gateway-mailer.client_id'),
            $dsn->getPassword() ?? $dsn->getOption('client_secret') ?? config('gateway-mailer.client_secret'),
            Cache::store($store),
            $cacheKey,
        );
    }

    protected function getSupportedSchemes(): array
    {
        return ['gateway'];
    }
}
