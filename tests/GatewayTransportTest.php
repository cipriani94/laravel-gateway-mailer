<?php

namespace Cipriani\GatewayMailer\Tests;

use Cipriani\GatewayMailer\Exceptions\GatewayTransportException;
use Cipriani\GatewayMailer\Transport\GatewayTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class GatewayTransportTest extends TestCase
{
    private const BASE_URL = 'https://gateway.example.test/api';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, response: \Psr\Http\Message\ResponseInterface|null}> */
    private array $history = [];

    private function makeTransport(array $mockResponses, ?CacheRepository $cache = null): GatewayTransport
    {
        $this->history = [];

        $mock = new MockHandler($mockResponses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $client = new Client(['handler' => $stack]);

        return new GatewayTransport(
            $client,
            self::BASE_URL,
            'client-id',
            'client-secret',
            $cache ?? new CacheRepository(new ArrayStore()),
        );
    }

    private function makeEmail(
        string $from = 'noreply@example.com',
        string $fromName = 'Acme Srl',
        string|array $to = 'destinatario@example.com',
    ): Email {
        $email = (new Email())
            ->from(new Address($from, $fromName))
            ->subject('Conferma ordine')
            ->html("<h1>Grazie per l'ordine</h1>");

        foreach ((array) $to as $address) {
            $email->addTo($address);
        }

        return $email;
    }

    private function loginResponse(string $token = 'plain-text-token', ?string $expiresAt = null): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'token' => $token,
            'expires_at' => $expiresAt ?? gmdate('Y-m-d\TH:i:s.000000\Z', time() + 30 * 24 * 60 * 60),
        ]));
    }

    private function successMailResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => true]));
    }

    public function test_it_logs_in_and_sends_mail_with_bearer_token(): void
    {
        $transport = $this->makeTransport([
            $this->loginResponse('the-token'),
            $this->successMailResponse(),
        ]);

        $transport->send($this->makeEmail());

        $this->assertCount(2, $this->history);

        $loginRequest = $this->history[0]['request'];
        $this->assertSame('POST', $loginRequest->getMethod());
        $this->assertSame(self::BASE_URL.'/auth/login', (string) $loginRequest->getUri());
        $this->assertStringStartsWith('Basic ', $loginRequest->getHeaderLine('Authorization'));
        $this->assertSame(
            'Basic '.base64_encode('client-id:client-secret'),
            $loginRequest->getHeaderLine('Authorization')
        );

        $mailRequest = $this->history[1]['request'];
        $this->assertSame('POST', $mailRequest->getMethod());
        $this->assertSame(self::BASE_URL.'/mail', (string) $mailRequest->getUri());
        $this->assertSame('Bearer the-token', $mailRequest->getHeaderLine('Authorization'));

        $payload = json_decode((string) $mailRequest->getBody(), true);
        $this->assertSame([
            'to' => 'destinatario@example.com',
            'subject' => 'Conferma ordine',
            'body' => "<h1>Grazie per l'ordine</h1>",
            'from' => 'noreply@example.com',
            'from_name' => 'Acme Srl',
        ], $payload);
    }

    public function test_from_name_falls_back_to_address_when_missing(): void
    {
        $transport = $this->makeTransport([
            $this->loginResponse(),
            $this->successMailResponse(),
        ]);

        $transport->send($this->makeEmail(from: 'noreply@example.com', fromName: ''));

        $payload = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('noreply@example.com', $payload['from_name']);
    }

    public function test_it_reuses_cached_token_and_skips_login_on_second_send(): void
    {
        $cache = new CacheRepository(new ArrayStore());

        $transport = $this->makeTransport([
            $this->loginResponse('cached-token'),
            $this->successMailResponse(),
            $this->successMailResponse(),
        ], $cache);

        $transport->send($this->makeEmail());
        $transport->send($this->makeEmail());

        $this->assertCount(3, $this->history);
        $this->assertSame(self::BASE_URL.'/auth/login', (string) $this->history[0]['request']->getUri());
        $this->assertSame(self::BASE_URL.'/mail', (string) $this->history[1]['request']->getUri());
        $this->assertSame(self::BASE_URL.'/mail', (string) $this->history[2]['request']->getUri());
        $this->assertSame('Bearer cached-token', $this->history[2]['request']->getHeaderLine('Authorization'));
    }

    public function test_it_relogs_in_and_retries_once_on_401(): void
    {
        $cache = new CacheRepository(new ArrayStore());
        $cache->put('gateway-mailer.token', 'stale-token', 3600);

        $transport = $this->makeTransport([
            new Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthorized'])),
            $this->loginResponse('fresh-token'),
            $this->successMailResponse(),
        ], $cache);

        $transport->send($this->makeEmail());

        $this->assertCount(3, $this->history);
        $this->assertSame('Bearer stale-token', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(self::BASE_URL.'/auth/login', (string) $this->history[1]['request']->getUri());
        $this->assertSame('Bearer fresh-token', $this->history[2]['request']->getHeaderLine('Authorization'));
        $this->assertSame('fresh-token', $cache->get('gateway-mailer.token'));
    }

    public function test_it_throws_when_still_unauthorized_after_retry(): void
    {
        $cache = new CacheRepository(new ArrayStore());
        $cache->put('gateway-mailer.token', 'stale-token', 3600);

        $transport = $this->makeTransport([
            new Response(401, [], json_encode(['error' => 'Unauthorized'])),
            $this->loginResponse('fresh-token'),
            new Response(401, [], json_encode(['error' => 'Unauthorized'])),
        ], $cache);

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('HTTP 401');

        $transport->send($this->makeEmail());
    }

    public function test_it_throws_on_login_failure_with_invalid_credentials(): void
    {
        $transport = $this->makeTransport([
            new Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Invalid credentials'])),
        ]);

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $transport->send($this->makeEmail());
    }

    public function test_it_throws_on_client_not_active_response(): void
    {
        $transport = $this->makeTransport([
            $this->loginResponse(),
            new Response(420, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'message' => 'Client non attivo',
            ])),
        ]);

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('Client non attivo');

        $transport->send($this->makeEmail());
    }

    public function test_it_throws_on_blacklisted_recipient_response(): void
    {
        $transport = $this->makeTransport([
            $this->loginResponse(),
            new Response(421, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'message' => 'Blacklist email',
            ])),
        ]);

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('Blacklist email');

        $transport->send($this->makeEmail());
    }

    public function test_it_throws_on_validation_error_response(): void
    {
        $transport = $this->makeTransport([
            $this->loginResponse(),
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'The to field is required.',
                'errors' => ['to' => ['The to field is required.']],
            ])),
        ]);

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('The to field is required.');

        $transport->send($this->makeEmail());
    }

    public function test_it_throws_on_invalid_login_response_body(): void
    {
        $transport = $this->makeTransport([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['unexpected' => 'shape'])),
        ]);

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('non valida');

        $transport->send($this->makeEmail());
    }

    public function test_it_rejects_messages_with_more_than_one_recipient(): void
    {
        $transport = $this->makeTransport([]);

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('to');

        $transport->send($this->makeEmail(to: ['a@example.com', 'b@example.com']));

        $this->assertCount(0, $this->history);
    }

    public function test_it_rejects_messages_with_cc(): void
    {
        $transport = $this->makeTransport([]);

        $email = $this->makeEmail()->cc('cc@example.com');

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('cc');

        $transport->send($email);
    }

    public function test_it_rejects_messages_with_bcc(): void
    {
        $transport = $this->makeTransport([]);

        $email = $this->makeEmail()->bcc('bcc@example.com');

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('bcc');

        $transport->send($email);
    }

    public function test_it_rejects_messages_with_attachments(): void
    {
        $transport = $this->makeTransport([]);

        $email = $this->makeEmail()->attach('contenuto file', 'allegato.txt', 'text/plain');

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('allegat');

        $transport->send($email);
    }

    public function test_it_rejects_messages_with_multiple_senders(): void
    {
        $transport = $this->makeTransport([]);

        $email = $this->makeEmail();
        $email->addFrom(new Address('other@example.com', 'Altro'));

        $this->expectException(GatewayTransportException::class);
        $this->expectExceptionMessage('from');

        $transport->send($email);
    }

    public function test_it_does_not_perform_any_http_call_for_unsupported_messages(): void
    {
        $transport = $this->makeTransport([]);

        try {
            $transport->send($this->makeEmail()->cc('cc@example.com'));
        } catch (GatewayTransportException) {
            // atteso
        }

        $this->assertCount(0, $this->history);
    }

    public function test___toString_returns_gateway(): void
    {
        $transport = $this->makeTransport([]);

        $this->assertSame('gateway', (string) $transport);
    }
}
