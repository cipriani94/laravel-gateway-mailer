<?php

namespace Cipriani\GatewayMailer\Tests;

use Cipriani\GatewayMailer\Transport\GatewayTransport;
use Illuminate\Support\Facades\Mail;
use ReflectionObject;

class GatewayMailerServiceProviderTest extends TestCase
{
    public function test_it_merges_the_package_default_config(): void
    {
        $this->assertSame('https://gateway.example.test/api', config('gateway-mailer.base_url'));
        $this->assertSame('client-id', config('gateway-mailer.client_id'));
        $this->assertSame('client-secret', config('gateway-mailer.client_secret'));
        $this->assertSame('gateway-mailer.token', config('gateway-mailer.cache.key'));
    }

    public function test_shipped_config_has_no_hardcoded_base_url_default(): void
    {
        $config = require __DIR__.'/../config/gateway-mailer.php';

        $this->assertNull($config['base_url']);
    }

    public function test_it_registers_a_gateway_mail_driver_built_from_mailer_config(): void
    {
        config()->set('mail.mailers.gateway', [
            'transport' => 'gateway',
            'base_url' => 'https://gateway-staging.example.test/api',
            'client_id' => 'mailer-config-client',
            'client_secret' => 'mailer-config-secret',
        ]);

        $transport = Mail::mailer('gateway')->getSymfonyTransport();

        $this->assertInstanceOf(GatewayTransport::class, $transport);
        $this->assertSame('https://gateway-staging.example.test/api', $this->readProperty($transport, 'baseUrl'));
        $this->assertSame('mailer-config-client', $this->readProperty($transport, 'clientId'));
        $this->assertSame('mailer-config-secret', $this->readProperty($transport, 'clientSecret'));
    }

    public function test_gateway_mail_driver_falls_back_to_package_config_when_mailer_omits_values(): void
    {
        config()->set('mail.mailers.gateway', [
            'transport' => 'gateway',
        ]);

        $transport = Mail::mailer('gateway')->getSymfonyTransport();

        $this->assertSame('https://gateway.example.test/api', $this->readProperty($transport, 'baseUrl'));
        $this->assertSame('client-id', $this->readProperty($transport, 'clientId'));
    }

    public function test_config_file_is_publishable(): void
    {
        $this->assertFileExists(__DIR__.'/../config/gateway-mailer.php');
    }

    private function readProperty(object $object, string $name): mixed
    {
        $property = (new ReflectionObject($object))->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }
}
