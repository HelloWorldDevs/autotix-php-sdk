<?php

declare(strict_types=1);

namespace Autotix\PhpSdk\Tests\Unit;

use Autotix\PhpSdk\UrlExtractor;
use PHPUnit\Framework\TestCase;

final class UrlExtractorTest extends TestCase
{
    public function testReturnsEmptyArrayForTextWithNoUrls(): void
    {
        $this->assertSame([], UrlExtractor::extract('plain text, no urls'));
    }

    public function testExtractsHttpsUrl(): void
    {
        $this->assertSame(
            ['https://example.com/path'],
            UrlExtractor::extract('error at https://example.com/path during checkout'),
        );
    }

    public function testPromotesWwwToHttps(): void
    {
        $this->assertSame(
            ['https://www.example.com'],
            UrlExtractor::extract('see www.example.com for details'),
        );
    }

    public function testStripsTrailingPunctuation(): void
    {
        $this->assertSame(
            ['https://example.com'],
            UrlExtractor::extract('visit https://example.com.'),
        );
        $this->assertSame(
            ['https://example.com/page'],
            UrlExtractor::extract('see (https://example.com/page) for more'),
        );
    }

    public function testDeduplicatesPreservingOrder(): void
    {
        $this->assertSame(
            ['https://b.com', 'https://a.com'],
            UrlExtractor::extract('https://b.com then https://a.com then https://b.com again'),
        );
    }

    public function testHandlesMultipleUrlsInStackTrace(): void
    {
        $stack = "Error in handler\n  at https://api.example.com/v1/users\n  triggered by https://app.example.com/checkout";
        $this->assertEqualsCanonicalizing(
            [
                'https://api.example.com/v1/users',
                'https://app.example.com/checkout',
            ],
            UrlExtractor::extract($stack),
        );
    }

    public function testIgnoresInvalidPrefixes(): void
    {
        // ftp:// is not a recognized prefix; only http(s) and www. are extracted.
        $this->assertSame(
            [],
            UrlExtractor::extract('ftp://files.example.com/file.zip'),
        );
    }
}
