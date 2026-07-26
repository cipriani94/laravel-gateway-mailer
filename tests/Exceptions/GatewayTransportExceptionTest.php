<?php

namespace Cipriani\GatewayMailer\Tests\Exceptions;

use Cipriani\GatewayMailer\Exceptions\GatewayTransportException;
use PHPUnit\Framework\TestCase;

class GatewayTransportExceptionTest extends TestCase
{
    public function test_unsupported_message_includes_reason(): void
    {
        $exception = GatewayTransportException::unsupportedMessage('non sono supportati allegati');

        $this->assertStringContainsString('non sono supportati allegati', $exception->getMessage());
    }

    public function test_from_login_response_extracts_error_key(): void
    {
        $exception = GatewayTransportException::fromLoginResponse(401, json_encode(['error' => 'Invalid credentials']));

        $this->assertStringContainsString('HTTP 401', $exception->getMessage());
        $this->assertStringContainsString('Invalid credentials', $exception->getMessage());
    }

    public function test_from_mail_response_extracts_message_key(): void
    {
        $exception = GatewayTransportException::fromMailResponse(422, json_encode([
            'message' => 'The to field is required.',
        ]));

        $this->assertStringContainsString('HTTP 422', $exception->getMessage());
        $this->assertStringContainsString('The to field is required.', $exception->getMessage());
    }

    public function test_from_mail_response_falls_back_to_raw_body_when_not_json(): void
    {
        $exception = GatewayTransportException::fromMailResponse(500, 'Internal Server Error');

        $this->assertStringContainsString('Internal Server Error', $exception->getMessage());
    }

    public function test_invalid_login_response_includes_body(): void
    {
        $exception = GatewayTransportException::invalidLoginResponse('{"unexpected":"shape"}');

        $this->assertStringContainsString('non valida', $exception->getMessage());
        $this->assertStringContainsString('unexpected', $exception->getMessage());
    }
}
