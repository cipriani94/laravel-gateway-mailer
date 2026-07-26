<?php

namespace Cipriani\GatewayMailer\Tests;

use Cipriani\GatewayMailer\Transport\GatewayTransport;
use Cipriani\GatewayMailer\Transport\GatewayTransportFactory;
use ReflectionObject;
use Symfony\Component\Mailer\Transport\Dsn;

class GatewayTransportFactoryTest extends TestCase
{
    public function test_it_builds_a_transport_from_explicit_dsn_options(): void
    {
        $factory = new GatewayTransportFactory();

        $dsn = Dsn::fromString(
            'gateway://dsn-client:dsn-secret@default?base_url=https://gateway-staging.example.test/api'
        );

        $transport = $factory->create($dsn);

        $this->assertInstanceOf(GatewayTransport::class, $transport);
        $this->assertSame('https://gateway-staging.example.test/api', $this->readProperty($transport, 'baseUrl'));
        $this->assertSame('dsn-client', $this->readProperty($transport, 'clientId'));
        $this->assertSame('dsn-secret', $this->readProperty($transport, 'clientSecret'));
    }

    public function test_it_falls_back_to_config_when_dsn_has_no_credentials_or_base_url(): void
    {
        $factory = new GatewayTransportFactory();

        $transport = $factory->create(Dsn::fromString('gateway://default'));

        $this->assertSame('https://gateway.example.test/api', $this->readProperty($transport, 'baseUrl'));
        $this->assertSame('client-id', $this->readProperty($transport, 'clientId'));
        $this->assertSame('client-secret', $this->readProperty($transport, 'clientSecret'));
    }

    public function test_it_only_supports_the_gateway_scheme(): void
    {
        $factory = new GatewayTransportFactory();

        $this->assertTrue($factory->supports(Dsn::fromString('gateway://default')));
        $this->assertFalse($factory->supports(Dsn::fromString('smtp://default')));
    }

    private function readProperty(object $object, string $name): mixed
    {
        $property = (new ReflectionObject($object))->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }
}
