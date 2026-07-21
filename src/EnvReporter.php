<?php

declare(strict_types=1);

namespace Autotix\PhpSdk;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Framework-agnostic reporter for the Autotix Vulnerability Scanning
 * feature.  POSTs a site's environment fingerprint (platform + runtime
 * + resolved package versions) to /api/telemetry/env, authenticated
 * with the same webhook token the error webhook uses.
 *
 * Each framework module (WordPress / Drupal / Laravel) collects its own
 * package list — WordPress via get_plugins(), Drupal via the module
 * list, Laravel by parsing composer.lock — and hands the normalized
 * report here.  The fingerprint algorithm matches the server
 * (src/lib/services/env-fingerprint.ts) and the JS SDK reporter so all
 * three agree on when "nothing changed".
 *
 * Callers should:
 *   1. Collect the env once (on activation, and on a ~monthly schedule).
 *   2. Compute fingerprint() and compare to the last-sent value they
 *      persisted.  If unchanged and the recheck window hasn't elapsed,
 *      skip the network call.
 *   3. Otherwise call report(); on success, persist the new fingerprint
 *      and next-check time.
 */
final class EnvReporter
{
    /** Telemetry endpoint — not configurable (mirrors WebhookClient). */
    public const TELEMETRY_URL = 'https://app.autotix.io/api/telemetry/env';

    /**
     * @param array{
     *   auth_token?: ?string,
     *   debug?: bool,
     *   user_agent?: string,
     * } $config
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $config = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Details of the most recent report() response, or null before any
     * call.  `stored` distinguishes "accepted" from "declined" — the server
     * answers 200 with stored:false (+ a human-readable `reason`) when the
     * customer hasn't enabled Vulnerability Scanning, and host modules
     * surface that on their status page.
     *
     * @var array{status: int, stored: ?bool, reason: ?string, changed: ?bool}|null
     */
    private ?array $lastResult = null;

    /**
     * @return array{status: int, stored: ?bool, reason: ?string, changed: ?bool}|null
     */
    public function getLastResult(): ?array
    {
        return $this->lastResult;
    }

    /**
     * Report the environment.  Returns true on a 2xx.
     *
     * @param array{
     *   siteUrl: string,
     *   platform: string,
     *   platformVersion?: ?string,
     *   runtime?: ?string,
     *   packages: array<int, array{name: string, version: string, ecosystem?: string}>,
     *   projectKey?: ?string,
     * } $report
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface Network failure.
     * @throws \RuntimeException JSON encoding failure.
     */
    public function report(array $report): bool
    {
        try {
            $body = json_encode(
                $report,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'Failed to encode Autotix env report as JSON',
                0,
                $e,
            );
        }

        $request = $this->requestFactory
            ->createRequest('POST', self::TELEMETRY_URL)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(
                'User-Agent',
                $this->config['user_agent'] ?? 'autotix-php-sdk/0.1',
            )
            ->withBody($this->streamFactory->createStream($body));

        $token = $this->config['auth_token'] ?? null;
        if (!empty($token)) {
            $request = $request->withHeader('X-Webhook-Token', $token);
        }

        $response = $this->httpClient->sendRequest($request);
        $status = $response->getStatusCode();

        // Capture the server's verdict for status pages: a 200 can still be
        // a decline (stored:false + reason) when the customer hasn't enabled
        // Vulnerability Scanning. Parse fail-soft — a non-JSON body just
        // leaves stored/reason null.
        $decoded = null;
        try {
            $decoded = json_decode((string) $response->getBody(), true);
        } catch (\Throwable) {
            // ignore — body is optional detail
        }
        $this->lastResult = [
            'status' => $status,
            'stored' => is_array($decoded) && array_key_exists('stored', $decoded)
                ? (bool) $decoded['stored']
                : null,
            'reason' => is_array($decoded) && isset($decoded['reason'])
                ? (string) $decoded['reason']
                : null,
            'changed' => is_array($decoded) && array_key_exists('changed', $decoded)
                ? (bool) $decoded['changed']
                : null,
        ];

        if (!empty($this->config['debug'])) {
            $this->logger?->debug(
                'Autotix: env report response',
                ['status' => $status],
            );
        }

        return $status >= 200 && $status < 300;
    }

    /**
     * Deterministic fingerprint of an environment — matches the server's
     * computeFingerprint() and the JS SDK's fingerprintEnvironment().
     *
     * Packages are sorted by (ecosystem, name, version) before hashing so
     * report ordering doesn't change the result.  Each package that omits
     * an ecosystem is treated with the given $defaultEcosystem.
     *
     * @param array<int, array{name: string, version: string, ecosystem?: string}> $packages
     */
    public static function fingerprint(
        string $platform,
        ?string $platformVersion,
        ?string $runtime,
        array $packages,
        string $defaultEcosystem = 'Packagist'
    ): string {
        $normalized = [];
        foreach ($packages as $p) {
            if (empty($p['name']) || empty($p['version'])) {
                continue;
            }
            $normalized[] = [
                'ecosystem' => $p['ecosystem'] ?? $defaultEcosystem,
                'name' => $p['name'],
                'version' => $p['version'],
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            return [$a['ecosystem'], $a['name'], $a['version']]
                <=> [$b['ecosystem'], $b['name'], $b['version']];
        });

        $parts = [$platform, $platformVersion ?? '', $runtime ?? ''];
        foreach ($normalized as $p) {
            $parts[] = "{$p['ecosystem']}:{$p['name']}@{$p['version']}";
        }

        return hash('sha256', implode("\n", $parts));
    }
}
