<?php

namespace Cipriani\GatewayMailer;

use Cipriani\GatewayMailer\Transport\GatewayTransport;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class GatewayMailerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/gateway-mailer.php' => config_path('gateway-mailer.php'),
        ], 'config');

        Mail::extend('gateway', function (array $config = []) {
            $store = $config['cache']['store'] ?? config('gateway-mailer.cache.store');
            $cacheKey = $config['cache']['key'] ?? config('gateway-mailer.cache.key', 'gateway-mailer.token');

            return new GatewayTransport(
                new Client(),
                $config['base_url'] ?? config('gateway-mailer.base_url'),
                $config['client_id'] ?? config('gateway-mailer.client_id'),
                $config['client_secret'] ?? config('gateway-mailer.client_secret'),
                Cache::store($store),
                $cacheKey,
            );
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/gateway-mailer.php', 'gateway-mailer'
        );
    }
}
