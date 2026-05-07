<?php

declare(strict_types=1);

namespace Autotix\PhpSdk;

/**
 * Formats a PHP `debug_backtrace()` array into a stack-trace-style string.
 *
 * Identical implementation existed in Drupal/WP/Laravel modules; lives
 * here so future improvements (e.g. argument redaction, source-line
 * fetching) ship to all consumers via composer update.
 */
final class BacktraceFormatter
{
    /**
     * @param array<int, array<string, mixed>> $frames Output of debug_backtrace().
     */
    public static function format(array $frames): string
    {
        $lines = [];
        foreach ($frames as $i => $frame) {
            $file = isset($frame['file']) && is_string($frame['file'])
                ? $frame['file']
                : '(unknown)';
            $line = isset($frame['line']) && is_int($frame['line'])
                ? $frame['line']
                : '?';
            $func = isset($frame['function']) && is_string($frame['function'])
                ? $frame['function']
                : '(unknown)';
            $class = isset($frame['class'], $frame['type']) &&
                is_string($frame['class']) && is_string($frame['type'])
                ? $frame['class'] . $frame['type']
                : '';
            $lines[] = "#{$i} {$file}:{$line} {$class}{$func}()";
        }
        return implode("\n", $lines);
    }
}
