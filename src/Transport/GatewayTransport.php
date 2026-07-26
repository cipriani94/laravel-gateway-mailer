<?php

namespace Cipriani\GatewayMailer\Transport;

use Cipriani\GatewayMailer\Exceptions\GatewayTransportException;
use DateTimeImmutable;
use Exception;
use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class GatewayTransport extends AbstractTransport
{
    private const LOGIN_PATH = '/auth/login';
    private const MAIL_PATH = '/mail';
    private const DEFAULT_TOKEN_TTL_SECONDS = 30 * 24 * 60 * 60;
    private const TOKEN_EXPIRY_SAFETY_MARGIN_SECONDS = 60;

    protected string $baseUrl;

    public function __construct(
        protected ClientInterface $client,
        string $baseUrl,
        protected string $clientId,
        protected string $clientSecret,
        protected CacheRepository $cache,
        protected string $cacheKey = 'gateway-mailer.token',
    ) {
        parent::__construct();

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $payload = $this->buildPayload($email);

        $this->sendWithToken($payload, retryOnUnauthorized: true);
    }

    private function sendWithToken(array $payload, bool $retryOnUnauthorized): void
    {
        $token = $this->resolveToken();

        $response = $this->client->request('POST', $this->baseUrl.self::MAIL_PATH, [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
            'json' => $payload,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();

        if ($status === 200) {
            return;
        }

        if ($status === 401 && $retryOnUnauthorized) {
            $this->cache->forget($this->cacheKey);
            $this->sendWithToken($payload, retryOnUnauthorized: false);

            return;
        }

        throw GatewayTransportException::fromMailResponse($status, (string) $response->getBody());
    }

    private function resolveToken(): string
    {
        $token = $this->cache->get($this->cacheKey);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->login();
    }

    private function login(): string
    {
        $response = $this->client->request('POST', $this->baseUrl.self::LOGIN_PATH, [
            'auth' => [$this->clientId, $this->clientSecret],
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status !== 200) {
            throw GatewayTransportException::fromLoginResponse($status, $body);
        }

        $data = json_decode($body, true);

        if (! is_array($data) || empty($data['token'])) {
            throw GatewayTransportException::invalidLoginResponse($body);
        }

        $token = (string) $data['token'];

        $this->cache->put($this->cacheKey, $token, $this->resolveTtl($data['expires_at'] ?? null));

        return $token;
    }

    private function resolveTtl(?string $expiresAt): int
    {
        if (! $expiresAt) {
            return self::DEFAULT_TOKEN_TTL_SECONDS;
        }

        try {
            $seconds = (new DateTimeImmutable($expiresAt))->getTimestamp()
                - (new DateTimeImmutable())->getTimestamp()
                - self::TOKEN_EXPIRY_SAFETY_MARGIN_SECONDS;
        } catch (Exception) {
            return self::DEFAULT_TOKEN_TTL_SECONDS;
        }

        return $seconds > 0 ? $seconds : self::DEFAULT_TOKEN_TTL_SECONDS;
    }

    protected function buildPayload(Email $email): array
    {
        $this->guardSupportedMessage($email);

        $from = $email->getFrom()[0];

        return [
            'to' => $email->getTo()[0]->getAddress(),
            'subject' => (string) $email->getSubject(),
            'body' => (string) ($email->getHtmlBody() ?? $email->getTextBody()),
            'from' => $from->getAddress(),
            'from_name' => $from->getName() !== '' ? $from->getName() : $from->getAddress(),
        ];
    }

    private function guardSupportedMessage(Email $email): void
    {
        if (count($email->getTo()) !== 1) {
            throw GatewayTransportException::unsupportedMessage(
                'e supportato un solo destinatario "to", ricevuti: '.count($email->getTo())
            );
        }

        if (count($email->getCc()) > 0) {
            throw GatewayTransportException::unsupportedMessage('non sono supportati destinatari in cc');
        }

        if (count($email->getBcc()) > 0) {
            throw GatewayTransportException::unsupportedMessage('non sono supportati destinatari in bcc');
        }

        if (count($email->getAttachments()) > 0) {
            throw GatewayTransportException::unsupportedMessage('non sono supportati allegati');
        }

        if (count($email->getFrom()) !== 1) {
            throw GatewayTransportException::unsupportedMessage('e richiesto esattamente un mittente "from"');
        }
    }

    public function __toString(): string
    {
        return 'gateway';
    }
}
