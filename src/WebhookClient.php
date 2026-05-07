<?php

declare(strict_types=1);

namespace Autotix\PhpSdk;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Framework-agnostic HTTP client that POSTs an Autotix payload to the webhook
 * endpoint. Handles JSON encoding, auth header construction, debug logging,
 * and outcome recording — but knows nothing about Drupal, WordPress, or
 * Laravel.
 *
 * Each framework module wraps this with thin adapter code that wires up the
 * framework's HTTP client, logger, and state store.
 */
final class WebhookClient
{
    /** Production webhook endpoint — not configurable. */
    public const WEBHOOK_URL = 'https://app.autotix.io/api/webhook/error';

    public const AUTH_TOKEN = 'token';
    public const AUTH_HMAC = 'hmac';
    public const AUTH_NONE = 'none';

    /**
     * @param ClientInterface $httpClient
     *   PSR-18 HTTP client. Drupal/WP/Laravel all ship with Guzzle, but
     *   any conformant client works.
     * @param RequestFactoryInterface $requestFactory
     *   PSR-17 request factory. Use guzzlehttp/psr7 or http-interop/factory.
     * @param StreamFactoryInterface $streamFactory
     *   PSR-17 stream factory.
     * @param array{
     *   auth_method?: 'token'|'hmac'|'none',
     *   auth_token?: ?string,
     *   auth_secret?: ?string,
     *   timeout?: int,
     *   debug?: bool,
     *   user_agent?: string,
     * } $config
     * @param StateRecorderInterface|null $stateRecorder
     *   Optional sink for delivery outcomes. Use this so admin dashboards
     *   can show "last_status" / counters without each framework hand-rolling
     *   it. If null, outcomes are silently dropped.
     * @param LoggerInterface|null $logger
     *   PSR-3 logger. Debug requests log here when `debug` is true; warnings
     *   on non-2xx always log here.
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $config = [],
        private readonly ?StateRecorderInterface $stateRecorder = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Send a payload to the Autotix webhook.
     *
     * @param array<string, mixed> $payload
     *   Wire-format payload — `source`, `level`, `message`, `url`, `details`.
     *
     * @return bool TRUE on 2xx response.
     *
     * @throws \RuntimeException
     *   When JSON encoding fails or the endpoint returns a non-2xx response.
     *   Callers (e.g. queue workers) should let this bubble up so the item
     *   is retried.
     * @throws ClientExceptionInterface
     *   On network-level failures (DNS, timeout, connection refused).
     *   Same retry contract as RuntimeException.
     */
    public function send(array $payload): bool
    {
        $url = self::WEBHOOK_URL;
        $debug = (bool) ($this->config['debug'] ?? false);

        try {
            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'Failed to encode Autotix webhook payload as JSON',
                0,
                $e,
            );
        }

        $request = $this->requestFactory
            ->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(
                'User-Agent',
                $this->config['user_agent'] ?? 'autotix-php-sdk/0.1',
            )
            ->withBody($this->streamFactory->createStream($body));

        // Auth — token method sets a header, hmac signs the body.
        $authMethod = $this->config['auth_method'] ?? self::AUTH_TOKEN;
        if ($authMethod === self::AUTH_TOKEN) {
            $token = $this->config['auth_token'] ?? null;
            if (!empty($token)) {
                $request = $request->withHeader('X-Webhook-Token', $token);
            }
        } elseif ($authMethod === self::AUTH_HMAC) {
            $secret = $this->config['auth_secret'] ?? null;
            if (!empty($secret)) {
                $signature = hash_hmac('sha256', $body, $secret);
                $request = $request->withHeader('X-Webhook-Signature', $signature);
            }
        }

        if ($debug) {
            $this->logger?->debug(
                'Autotix: sending request',
                [
                    'url' => $url,
                    'payload_url' => $payload['url'] ?? '(none)',
                    'source' => $payload['source'] ?? '(none)',
                    'level' => $payload['level'] ?? '(none)',
                    'message' => $this->trimMessage($payload['message'] ?? ''),
                ],
            );
        }

        // ClientExceptionInterface (network error) bubbles up — caller decides
        // whether to retry. This contract matches every pre-existing module.
        $response = $this->httpClient->sendRequest($request);
        $status = $response->getStatusCode();

        if ($debug) {
            $this->logger?->debug(
                'Autotix: response received',
                ['status' => $status, 'url' => $url],
            );
        }

        if ($status < 200 || $status >= 300) {
            // Body only logged in debug mode — never put it in the exception
            // message because some callers (queue workers, log aggregators)
            // serialize exception messages and the body could leak detail.
            if ($debug) {
                $this->logger?->debug(
                    'Autotix: non-2xx response body',
                    [
                        'status' => $status,
                        'url' => $url,
                        'response' => $this->trimMessage(
                            (string) $response->getBody(),
                        ),
                    ],
                );
            }

            $this->logger?->warning(
                'Autotix delivery FAILED — HTTP @status to @url',
                [
                    '@status' => $status,
                    '@url' => $url,
                    'source' => $payload['source'] ?? '(none)',
                    'level' => $payload['level'] ?? '(none)',
                ],
            );

            $this->stateRecorder?->recordOutcome('failed', [
                'status' => $status,
            ]);
            throw new \RuntimeException(sprintf(
                'Autotix webhook returned HTTP %d.',
                $status,
            ));
        }

        $this->logger?->info(
            'Autotix: delivered',
            [
                'url' => $url,
                'source' => $payload['source'] ?? '(none)',
                'level' => $payload['level'] ?? '(none)',
                'message' => $this->trimMessage($payload['message'] ?? '', 120),
            ],
        );

        $this->stateRecorder?->recordOutcome('ok', ['status' => $status]);
        return true;
    }

    /**
     * Trim a string to N chars + ellipsis. Keeps log lines / exception
     * messages from exploding when payloads are huge.
     */
    private function trimMessage(string $value, int $max = 200): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max) . '...';
    }
}
