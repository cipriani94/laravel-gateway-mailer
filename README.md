# Laravel Gateway Mailer

A Laravel mail transport that forwards outgoing email to the `Mail API — enricocipriani.it` gateway microservice (Amazon SES backed), as described in [`openapi.yaml`](openapi.yaml).

Instead of talking to SMTP or a provider SDK directly, this package authenticates against the gateway's `/auth/login` endpoint and posts each message to `/mail` using the Bearer token it receives back.

## Compatibility

| Laravel | Supported |
|---|---|
| 10.x | ✅ |
| 11.x | ✅ |
| 12.x | ✅ |
| 13.x | ✅ |

Requires PHP ^8.1. The full test suite (30 tests) is run against each Laravel major listed above.

## How it works

1. **Login** — on the first send, the transport does a Basic Auth `POST {base_url}/auth/login` with your `client_id`/`client_secret`. The gateway returns a Bearer `token` valid 30 days and revokes any token issued before it.
2. **Token caching** — the token is stored in Laravel's cache (not just in memory) so that concurrent requests and subsequent mail sends reuse it instead of logging in again — logging in again would otherwise invalidate a token still in use elsewhere.
3. **Send** — each email is posted to `POST {base_url}/mail` with `Authorization: Bearer <token>`, using the payload shape required by the gateway (`to`, `subject`, `body`, `from`, `from_name`).
4. **Automatic re-login on 401** — if the cached token is rejected (expired/revoked), the transport clears the cache, logs in again, and retries the send exactly once before giving up.
5. **Error mapping** — gateway error responses (`401`, `420`, `421`, `422`, or a malformed login response) are raised as a `Cipriani\GatewayMailer\Exceptions\GatewayTransportException` with the message extracted from the JSON body.

## Installation

```bash
composer require cipriani94/laravel-gateway-mailer
```

The service provider and the `gateway` mail driver are auto-discovered. Publish the config file if you want to customize it:

```bash
php artisan vendor:publish --tag=config --provider="Cipriani\GatewayMailer\GatewayMailerServiceProvider"
```

This creates `config/gateway-mailer.php`.

## Configuration

### 1. Environment variables

Add to your `.env`. There is **no hardcoded default URL** — you must explicitly pick one of the gateway environments (see `servers:` in [`openapi.yaml`](openapi.yaml)):

```dotenv
GATEWAY_MAILER_BASE_URL=https://mail.enricocipriani.it/api
GATEWAY_MAILER_CLIENT_ID=your-client-id
GATEWAY_MAILER_CLIENT_SECRET=your-client-secret

# Optional: which cache store to use for the Bearer token (defaults to your app's default store)
GATEWAY_MAILER_CACHE_STORE=redis
```

Other available gateway environments from the OpenAPI spec:

| Environment | `GATEWAY_MAILER_BASE_URL` |
|---|---|
| dev (local, docker) | `http://localhost:4010/api` |
| alpha | `https://alpha.mail.enricocipriani.it/api` |
| test | `https://test.mail.enricocipriani.it/api` |
| production | `https://mail.enricocipriani.it/api` |

### 2. Register the mailer

In `config/mail.php`, add a `gateway` mailer:

```php
'mailers' => [
    // ...
    'gateway' => [
        'transport' => 'gateway',
    ],
],
```

By default this falls back to the `GATEWAY_MAILER_*` env values above. You can also override per-mailer:

```php
'gateway' => [
    'transport' => 'gateway',
    'base_url' => env('GATEWAY_MAILER_BASE_URL'),
    'client_id' => env('GATEWAY_MAILER_CLIENT_ID'),
    'client_secret' => env('GATEWAY_MAILER_CLIENT_SECRET'),
    'cache' => [
        'store' => env('GATEWAY_MAILER_CACHE_STORE'),
        'key' => 'gateway-mailer.token',
    ],
],
```

### 3. Set it as the default mailer (optional)

```dotenv
MAIL_MAILER=gateway
```

Or use it explicitly per send:

```php
Mail::mailer('gateway')->to('destinatario@example.com')->send(new OrderConfirmed($order));
```

## Sending mail

Use Laravel's normal `Mail` facade / Mailable classes — no code changes are required beyond selecting the `gateway` mailer:

```php
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

Mail::mailer('gateway')->send([], [], function () {});

// or, more typically, with a Mailable:
Mail::mailer('gateway')
    ->to('destinatario@example.com')
    ->send(new OrderConfirmed($order));
```

Make sure the Mailable's `from` address is one of the client's authorized senders (`client.froms`), or the gateway will reject it with HTTP `420`.

## Important limitations

The gateway's `CreateMailRequest` schema only supports **one** recipient, one sender, and no attachments. The transport enforces this up front — before making any HTTP call — and raises `GatewayTransportException` if the message contains:

- more than one `to` recipient
- any `cc` recipient
- any `bcc` recipient
- any attachment
- more than one `from` address

Design mail sends accordingly (e.g. loop over recipients and send one message per recipient rather than using `cc`/`bcc`).

## Error handling

```php
use Cipriani\GatewayMailer\Exceptions\GatewayTransportException;

try {
    Mail::mailer('gateway')->to($email)->send(new OrderConfirmed($order));
} catch (GatewayTransportException $e) {
    // $e->getMessage() includes the HTTP status and the gateway's error/message body, e.g.:
    // "Invio email al gateway fallito (HTTP 421): Blacklist email"
}
```

| HTTP status | Meaning |
|---|---|
| `401` | Token missing/expired/invalid — the transport retries once automatically after a fresh login |
| `420` | Client not active, or `from` not among the client's authorized senders |
| `421` | Recipient is blacklisted for this client |
| `422` | Validation error (missing/invalid fields) |

## Using it outside of Laravel's Mail facade (Symfony DSN)

`GatewayTransportFactory` also lets you build the transport from a Symfony Mailer DSN, e.g. via `MAILER_DSN`:

```dotenv
MAILER_DSN=gateway://client-id:client-secret@default?base_url=https://mail.enricocipriani.it/api
```

Any option omitted from the DSN (`base_url`, user/password, `cache_store`, `cache_key`) falls back to `config('gateway-mailer.*')`.

## Running the test suite

```bash
composer install
vendor/bin/phpunit
```

The suite covers: the login → send flow, token caching/reuse, automatic re-login and retry on `401`, every gateway error response (`401`/`420`/`421`/`422`), malformed login responses, rejection of unsupported messages (multi-recipient, cc, bcc, attachments, multi-sender) with zero HTTP calls made, the DSN factory, and the service provider's Laravel `Mail` integration (via Orchestra Testbench).
