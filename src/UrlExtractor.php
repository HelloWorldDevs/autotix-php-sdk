<?php

declare(strict_types=1);

namespace Autotix\PhpSdk;

/**
 * Pulls URLs out of free-form error text (stack traces, log lines, email
 * bodies). Used by every Autotix framework module to populate the `url`
 * field of the wire payload when the framework doesn't give us one
 * directly.
 */
final class UrlExtractor
{
    private const PATTERNS = [
        '/https?:\/\/[^\s<>"\'`]+/',
        '/www\.[^\s<>"\'`]+/',
    ];

    /**
     * @return string[] Deduplicated URLs in first-seen order, with stray
     *   trailing punctuation stripped and `www.` prefixes promoted to https.
     */
    public static function extract(string $text): array
    {
        $urls = [];
        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $matches) > 0) {
                array_push($urls, ...$matches[0]);
            }
        }

        $cleaned = [];
        $seen = [];
        foreach ($urls as $url) {
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                if (str_starts_with($url, 'www.')) {
                    $url = 'https://' . $url;
                } else {
                    continue;
                }
            }
            // Strip trailing punctuation that the URL almost certainly
            // doesn't include (parens, periods, commas at end of sentence).
            $url = rtrim($url, ".,;:!?)\\]}'\"");
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $cleaned[] = $url;
            }
        }
        return $cleaned;
    }
}
