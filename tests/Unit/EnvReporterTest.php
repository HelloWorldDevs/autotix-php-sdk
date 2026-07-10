<?php

declare(strict_types=1);

namespace Autotix\PhpSdk\Tests\Unit;

use Autotix\PhpSdk\EnvReporter;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

final class EnvReporterTest extends TestCase
{
    /** @var ClientInterface&\PHPUnit\Framework\MockObject\MockObject */
    private ClientInterface $http;

    private HttpFactory $factory;

    private ?RequestInterface $sentRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = $this->createMock(ClientInterface::class);
        $this->factory = new HttpFactory();
        $this->sentRequest = null;
    }

    private function reporter(array $config = []): EnvReporter
    {
        return new EnvReporter($this->http, $this->factory, $this->factory, $config);
    }

    private function captureRequest(int $status = 200, string $body = '{"stored":true}'): void
    {
        $this->http
            ->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $req) use ($status, $body): Response {
                $this->sentRequest = $req;
                return new Response($status, [], $body);
            });
    }

    private const REPORT = [
        'siteUrl' => 'https://acme.example.com',
        'platform' => 'wordpress',
        'platformVersion' => '6.4.3',
        'runtime' => 'php 8.3.2',
        'packages' => [
            ['name' => 'woocommerce', 'version' => '8.5.0'],
        ],
        'projectKey' => 'WEB',
    ];

    public function testPostsToTelemetryEndpointWithTokenHeader(): void
    {
        $this->captureRequest(200);
        $ok = $this->reporter(['auth_token' => 'tok_abc'])->report(self::REPORT);

        $this->assertTrue($ok);
        $this->assertNotNull($this->sentRequest);
        $this->assertSame('POST', $this->sentRequest->getMethod());
        $this->assertSame(
            EnvReporter::TELEMETRY_URL,
            (string) $this->sentRequest->getUri(),
        );
        $this->assertSame('tok_abc', $this->sentRequest->getHeaderLine('X-Webhook-Token'));

        $sent = json_decode((string) $this->sentRequest->getBody(), true);
        $this->assertSame('wordpress', $sent['platform']);
        $this->assertSame('WEB', $sent['projectKey']);
    }

    public function testReturnsFalseOnNon2xx(): void
    {
        $this->captureRequest(429, '{"error":"rate limited"}');
        $ok = $this->reporter(['auth_token' => 't'])->report(self::REPORT);
        $this->assertFalse($ok);
    }

    public function testOmitsTokenHeaderWhenUnset(): void
    {
        $this->captureRequest(200);
        $this->reporter()->report(self::REPORT);
        $this->assertFalse($this->sentRequest->hasHeader('X-Webhook-Token'));
    }

    public function testFingerprintIsStableRegardlessOfPackageOrder(): void
    {
        $a = EnvReporter::fingerprint('wordpress', '6.4', 'php 8.3', [
            ['name' => 'b-plugin', 'version' => '1.0'],
            ['name' => 'a-plugin', 'version' => '2.0'],
        ]);
        $b = EnvReporter::fingerprint('wordpress', '6.4', 'php 8.3', [
            ['name' => 'a-plugin', 'version' => '2.0'],
            ['name' => 'b-plugin', 'version' => '1.0'],
        ]);
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    public function testFingerprintChangesWhenVersionChanges(): void
    {
        $a = EnvReporter::fingerprint('wordpress', '6.4', 'php 8.3', [
            ['name' => 'woocommerce', 'version' => '8.5.0'],
        ]);
        $b = EnvReporter::fingerprint('wordpress', '6.4', 'php 8.3', [
            ['name' => 'woocommerce', 'version' => '8.5.1'],
        ]);
        $this->assertNotSame($a, $b);
    }

    public function testFingerprintSkipsMalformedPackages(): void
    {
        $withJunk = EnvReporter::fingerprint('wordpress', '6.4', 'php 8.3', [
            ['name' => 'ok', 'version' => '1.0'],
            ['name' => '', 'version' => '2.0'],
            ['name' => 'noversion', 'version' => ''],
        ]);
        $clean = EnvReporter::fingerprint('wordpress', '6.4', 'php 8.3', [
            ['name' => 'ok', 'version' => '1.0'],
        ]);
        $this->assertSame($clean, $withJunk);
    }
}
