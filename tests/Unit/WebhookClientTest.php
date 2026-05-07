<?php

declare(strict_types=1);

namespace Autotix\PhpSdk\Tests\Unit;

use Autotix\PhpSdk\StateRecorderInterface;
use Autotix\PhpSdk\WebhookClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

final class WebhookClientTest extends TestCase
{
    /** @var ClientInterface&\PHPUnit\Framework\MockObject\MockObject */
    private ClientInterface $http;

    private HttpFactory $factory;

    /** Captured request from each $http->sendRequest() call. */
    private ?RequestInterface $sentRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = $this->createMock(ClientInterface::class);
        $this->factory = new HttpFactory();
        $this->sentRequest = null;
    }

    private function client(array $config = [], ?StateRecorderInterface $recorder = null, ?LoggerInterface $logger = null): WebhookClient
    {
        return new WebhookClient(
            $this->http,
            $this->factory,
            $this->factory,
            $config,
            $recorder,
            $logger,
        );
    }

    private function captureRequest(int $status = 202, string $body = '{"ok":true}'): void
    {
        $this->http
            ->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $req) use ($status, $body): Response {
                $this->sentRequest = $req;
                return new Response($status, [], $body);
            });
    }

    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    public function testSendReturnsTrueOn2xx(): void
    {
        $this->captureRequest(202);
        $result = $this->client()->send(['source' => 'test', 'message' => 'hi']);
        $this->assertTrue($result);
    }

    public function testPostsToHardcodedWebhookUrl(): void
    {
        $this->captureRequest();
        $this->client()->send(['message' => 'x']);
        $this->assertSame(
            'https://app.autotix.io/api/webhook/error',
            (string) $this->sentRequest->getUri(),
        );
        $this->assertSame('POST', $this->sentRequest->getMethod());
    }

    public function testEncodesPayloadAsJson(): void
    {
        $this->captureRequest();
        $payload = ['source' => 'drupal', 'message' => 'hello world', 'level' => 'error'];
        $this->client()->send($payload);

        $body = (string) $this->sentRequest->getBody();
        $this->assertSame($payload, json_decode($body, true));
        $this->assertSame(
            'application/json',
            $this->sentRequest->getHeaderLine('Content-Type'),
        );
    }

    public function testSetsDefaultUserAgent(): void
    {
        $this->captureRequest();
        $this->client()->send(['message' => 'x']);
        $this->assertStringStartsWith(
            'autotix-php-sdk/',
            $this->sentRequest->getHeaderLine('User-Agent'),
        );
    }

    public function testRespectsCustomUserAgent(): void
    {
        $this->captureRequest();
        $this->client(['user_agent' => 'autotix-drupal/1.2.3'])
            ->send(['message' => 'x']);
        $this->assertSame(
            'autotix-drupal/1.2.3',
            $this->sentRequest->getHeaderLine('User-Agent'),
        );
    }

    // -------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------

    public function testTokenAuthSetsHeader(): void
    {
        $this->captureRequest();
        $this->client(['auth_method' => 'token', 'auth_token' => 'iat_secret'])
            ->send(['message' => 'x']);
        $this->assertSame(
            'iat_secret',
            $this->sentRequest->getHeaderLine('X-Webhook-Token'),
        );
        $this->assertFalse($this->sentRequest->hasHeader('X-Webhook-Signature'));
    }

    public function testTokenAuthOmitsHeaderWhenTokenEmpty(): void
    {
        $this->captureRequest();
        $this->client(['auth_method' => 'token', 'auth_token' => ''])
            ->send(['message' => 'x']);
        $this->assertFalse($this->sentRequest->hasHeader('X-Webhook-Token'));
    }

    public function testHmacAuthSignsBody(): void
    {
        $this->captureRequest();
        $payload = ['message' => 'x'];
        $expectedBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $expectedSignature = hash_hmac('sha256', $expectedBody, 'shared-secret');

        $this->client(['auth_method' => 'hmac', 'auth_secret' => 'shared-secret'])
            ->send($payload);

        $this->assertSame(
            $expectedSignature,
            $this->sentRequest->getHeaderLine('X-Webhook-Signature'),
        );
        $this->assertFalse($this->sentRequest->hasHeader('X-Webhook-Token'));
    }

    public function testNoAuthHeadersWhenMethodIsNone(): void
    {
        $this->captureRequest();
        $this->client([
            'auth_method' => 'none',
            'auth_token' => 'ignored',
            'auth_secret' => 'ignored',
        ])->send(['message' => 'x']);
        $this->assertFalse($this->sentRequest->hasHeader('X-Webhook-Token'));
        $this->assertFalse($this->sentRequest->hasHeader('X-Webhook-Signature'));
    }

    // -------------------------------------------------------------------
    // Failure modes
    // -------------------------------------------------------------------

    public function testThrowsRuntimeExceptionOn4xx(): void
    {
        $this->captureRequest(403, 'Forbidden');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Autotix webhook returned HTTP 403.');
        $this->client()->send(['message' => 'x']);
    }

    public function testThrowsRuntimeExceptionOn5xx(): void
    {
        $this->captureRequest(500);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $this->client()->send(['message' => 'x']);
    }

    public function testExceptionMessageDoesNotLeakResponseBody(): void
    {
        $this->captureRequest(500, 'PII: user email leaked@example.com');
        try {
            $this->client()->send(['message' => 'x']);
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('leaked@example.com', $e->getMessage());
        }
    }

    public function testRethrowsJsonEncodeFailureAsRuntimeException(): void
    {
        // Resource handles can't be JSON-encoded.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode Autotix webhook payload as JSON');
        $this->client()->send(['handle' => fopen('php://memory', 'r')]);
    }

    public function testNetworkErrorBubblesUp(): void
    {
        $networkError = new class('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        $this->http->method('sendRequest')->willThrowException($networkError);
        $this->expectException(ClientExceptionInterface::class);
        $this->client()->send(['message' => 'x']);
    }

    // -------------------------------------------------------------------
    // State recorder
    // -------------------------------------------------------------------

    public function testRecordsOkOutcomeOnSuccess(): void
    {
        $this->captureRequest(202);
        $recorder = $this->createMock(StateRecorderInterface::class);
        $recorder->expects($this->once())
            ->method('recordOutcome')
            ->with('ok', $this->arrayHasKey('status'));
        $this->client([], $recorder)->send(['message' => 'x']);
    }

    public function testRecordsFailedOutcomeOnNon2xx(): void
    {
        $this->captureRequest(500);
        $recorder = $this->createMock(StateRecorderInterface::class);
        $recorder->expects($this->once())
            ->method('recordOutcome')
            ->with('failed', $this->arrayHasKey('status'));
        try {
            $this->client([], $recorder)->send(['message' => 'x']);
        } catch (\RuntimeException) {
            // Expected — outcome recorded BEFORE the throw.
        }
    }

    // -------------------------------------------------------------------
    // Debug logging
    // -------------------------------------------------------------------

    public function testNoDebugLogsWhenDebugIsFalse(): void
    {
        $this->captureRequest();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('debug');
        // info still fires on success; that's the always-on delivery log
        $this->client(['debug' => false], null, $logger)->send(['message' => 'x']);
    }

    public function testDebugLogsBeforeAndAfterRequest(): void
    {
        $this->captureRequest();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('debug');
        $this->client(['debug' => true], null, $logger)->send(['message' => 'x']);
    }

    public function testWarningLoggedOnNon2xxEvenWhenDebugIsFalse(): void
    {
        $this->captureRequest(500);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        try {
            $this->client(['debug' => false], null, $logger)->send(['message' => 'x']);
        } catch (\RuntimeException) {
            // Expected.
        }
    }
}
