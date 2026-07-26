# Laravel Gateway Mailer

A Laravel mail transport for sending email through a remote HTTP gateway API, using a login-then-Bearer-token authentication flow instead of SMTP or a provider-specific SDK.

It works with any HTTP gateway that exposes this contract:

- `POST {base_url}/auth/login` — Basic Auth (`client_id` / `client_secret`) → returns a Bearer `token` (plus an `expires_at`) that authenticates subsequent requests until it expires or a new login revokes it.
- `POST {base_url}/mail` — `Authorization: Bearer <token>`, JSON body `{ to, subject, body, from, from_name }` → sends a single email to a single recipient.

If your gateway matches this contract, this package is a drop-in Laravel mail driver for it.

## Compatibility

| Laravel | Supported |
|---|---|
| 10.x | ✅ |
| 11.x | ✅ |
| 12.x | ✅ |
| 13.x | ✅ |

Requires PHP ^8.1. The test suite is run against each Laravel major listed above.

## How it works

1. **Login** — on the first send, the transport does a Basic Auth `POST {base_url}/auth/login` with your `client_id`/`client_secret` and receives a Bearer `token`.
2. **Token caching** — the token is stored in Laravel's cache (not just in memory), so subsequent sends reuse it instead of logging in again. This matters if your gateway revokes the previous token on every login — re-logging in on every send would otherwise invalidate a token still in use elsewhere (e.g. another worker/process).
3. **Send** — each email is posted to `{base_url}/mail` with `Authorization: Bearer <token>` and a JSON payload of `to`, `subject`, `body`, `from`, `from_name`.
4. **Automatic re-login on 401** — if the cached token is rejected, the transport clears it, logs in again, and retries the send exactly once before giving up.
5. **Error mapping** — non-2xx gateway responses (e.g. `401`, `420`, `421`, `422`, or a malformed login response) are raised as a `Cipriani\GatewayMailer\Exceptions\GatewayTransportException`, with the message extracted from the response body when possible.

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

Add to your `.env`. There is **no hardcoded default URL** — you must always set the base URL of your gateway explicitly:

```dotenv
GATEWAY_MAILER_BASE_URL=https://your-gateway.example.com/api
GATEWAY_MAILER_CLIENT_ID=your-client-id
GATEWAY_MAILER_CLIENT_SECRET=your-client-secret

# Optional: which cache store to use for the Bearer token (defaults to your app's default store)
GATEWAY_MAILER_CACHE_STORE=redis
```

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

By default this falls back to the `GATEWAY_MAILER_*` env values above. You can also override per-mailer, e.g. to point different mailers at different gateways:

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
Mail::mailer('gateway')->to($recipient)->send(new OrderConfirmed($order));
```

## Sending mail

Use Laravel's normal `Mail` facade / Mailable classes — no code changes are required beyond selecting the `gateway` mailer:

```php
use Illuminate\Support\Facades\Mail;

Mail::mailer('gateway')
    ->to($recipient)
    ->send(new OrderConfirmed($order));
```

## Important limitations

The gateway contract this package targets only supports **one** recipient, **one** sender, and no attachments per request. The transport enforces this up front — before making any HTTP call — and raises `GatewayTransportException` if the message contains:

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
    Mail::mailer('gateway')->to($recipient)->send(new OrderConfirmed($order));
} catch (GatewayTransportException $e) {
    // $e->getMessage() includes the HTTP status and the gateway's error/message body.
}
```

Any non-2xx response from `/mail` (other than a `401` that gets retried once automatically) is surfaced as a `GatewayTransportException` — map your own gateway's status codes to your application's error handling as needed.

## Using it outside of Laravel's Mail facade (Symfony DSN)

`GatewayTransportFactory` also lets you build the transport from a Symfony Mailer DSN, e.g. via `MAILER_DSN`:

```dotenv
MAILER_DSN=gateway://client-id:client-secret@default?base_url=https://your-gateway.example.com/api
```

Any option omitted from the DSN (`base_url`, user/password, `cache_store`, `cache_key`) falls back to `config('gateway-mailer.*')`.

## Running the test suite

```bash
composer install
vendor/bin/phpunit
```

The suite covers: the login → send flow, token caching/reuse, automatic re-login and retry on `401`, gateway error responses, malformed login responses, rejection of unsupported messages (multi-recipient, cc, bcc, attachments, multi-sender) with zero HTTP calls made, the DSN factory, and the service provider's Laravel `Mail` integration (via Orchestra Testbench).
