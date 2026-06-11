# Autotix PHP SDK

Shared PHP core for the Autotix framework integrations ([Drupal](https://github.com/HelloWorldDevs/autotix-drupal), [WordPress](https://github.com/HelloWorldDevs/autotix-wordpress), [Laravel](https://github.com/HelloWorldDevs/autotix-laravel)). Wire format, webhook HTTP client, auth, and utilities — framework-agnostic.

[Autotix](https://autotix.io) turns production errors into tickets (Jira or GitHub Issues) and AI-generated fix PRs automatically.

> This repository is a read-only mirror, split from the Autotix monorepo. Issues and PRs are welcome here and will be applied upstream.

## Install

```bash
composer require autotix/php-sdk
```

Requires PHP >= 8.1 and any PSR-18 HTTP client (Guzzle works out of the box and is what Drupal, WordPress, and Laravel ship with).

## What's inside

| Class | Purpose |
| --- | --- |
| `Autotix\PhpSdk\WebhookClient` | Sends error payloads to the Autotix webhook. JSON encoding, `X-Webhook-Token` or HMAC-SHA256 auth, debug logging, delivery-outcome recording. |
| `Autotix\PhpSdk\BacktraceFormatter` | Formats `debug_backtrace()` output into a stack-trace string. |
| `Autotix\PhpSdk\UrlExtractor` | Extracts the request URL from the current environment for error attribution. |
| `Autotix\PhpSdk\StateRecorderInterface` | Optional sink for delivery outcomes (back it with Drupal state, WP options, Laravel cache, …). |

## Usage

```php
use Autotix\PhpSdk\WebhookClient;

$client = new WebhookClient(
    $psr18Client,          // any PSR-18 ClientInterface
    $psr17RequestFactory,  // PSR-17 RequestFactoryInterface
    $psr17StreamFactory,   // PSR-17 StreamFactoryInterface
    [
        'auth_method' => 'token',           // or 'hmac'
        'auth_token'  => 'your-org-token',  // from app.autotix.io → Settings
    ],
);

$client->send([
    'message'     => $exception->getMessage(),
    'stack_trace' => $exception->getTraceAsString(),
    'url'         => 'https://example.com/checkout',
    'environment' => 'production',
]);
```

If you're on Drupal, WordPress, or Laravel, use the corresponding integration instead — they wire all of this up for you.

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
