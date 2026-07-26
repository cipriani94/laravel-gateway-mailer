<?php

namespace Cipriani\GatewayMailer\Tests;

use Cipriani\GatewayMailer\GatewayMailerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [GatewayMailerServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('gateway-mailer.base_url', 'https://gateway.example.test/api');
        $app['config']->set('gateway-mailer.client_id', 'client-id');
        $app['config']->set('gateway-mailer.client_secret', 'client-secret');
    }
}
