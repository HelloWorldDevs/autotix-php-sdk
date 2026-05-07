<?php

declare(strict_types=1);

namespace Autotix\PhpSdk\Tests\Unit;

use Autotix\PhpSdk\BacktraceFormatter;
use PHPUnit\Framework\TestCase;

final class BacktraceFormatterTest extends TestCase
{
    public function testFormatsAFunctionFrame(): void
    {
        $out = BacktraceFormatter::format([
            ['file' => '/app/foo.php', 'line' => 42, 'function' => 'doThing'],
        ]);
        $this->assertSame('#0 /app/foo.php:42 doThing()', $out);
    }

    public function testFormatsAMethodFrame(): void
    {
        $out = BacktraceFormatter::format([
            [
                'file' => '/app/Bar.php',
                'line' => 10,
                'class' => 'My\\Bar',
                'type' => '->',
                'function' => 'run',
            ],
        ]);
        $this->assertSame('#0 /app/Bar.php:10 My\\Bar->run()', $out);
    }

    public function testJoinsMultipleFramesWithNewlines(): void
    {
        $out = BacktraceFormatter::format([
            ['file' => '/a.php', 'line' => 1, 'function' => 'a'],
            ['file' => '/b.php', 'line' => 2, 'function' => 'b'],
        ]);
        $this->assertSame(
            "#0 /a.php:1 a()\n#1 /b.php:2 b()",
            $out,
        );
    }

    public function testReplacesMissingFieldsWithSafeDefaults(): void
    {
        $out = BacktraceFormatter::format([[]]);
        $this->assertSame('#0 (unknown):? (unknown)()', $out);
    }

    public function testReturnsEmptyStringForNoFrames(): void
    {
        $this->assertSame('', BacktraceFormatter::format([]));
    }
}
