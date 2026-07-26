<?php

namespace Cipriani\GatewayMailer\Exceptions;

use RuntimeException;

class GatewayTransportException extends RuntimeException
{
    public static function unsupportedMessage(string $reason): self
    {
        return new self("Messaggio non supportato dal gateway: {$reason}");
    }

    public static function fromLoginResponse(int $status, string $body): self
    {
        return new self("Login al gateway fallito (HTTP {$status}): ".self::extractMessage($body));
    }

    public static function invalidLoginResponse(string $body): self
    {
        return new self('Risposta di login del gateway non valida: '.$body);
    }

    public static function fromMailResponse(int $status, string $body): self
    {
        return new self("Invio email al gateway fallito (HTTP {$status}): ".self::extractMessage($body));
    }

    private static function extractMessage(string $body): string
    {
        $data = json_decode($body, true);

        if (is_array($data)) {
            return (string) ($data['message'] ?? $data['error'] ?? $body);
        }

        return $body;
    }
}
