<?php

declare(strict_types=1);

namespace Autotix\PhpSdk;

/**
 * Optional sink for delivery outcomes. Each framework backs this with its
 * own store (Drupal state, WP options, Laravel cache, etc.) so admins can
 * see "last_status / total_delivered / total_failed" on a status widget
 * without each module hand-rolling that bookkeeping.
 *
 * Pass null to WebhookClient if you don't need this.
 */
interface StateRecorderInterface
{
    /**
     * @param 'ok'|'failed' $status
     * @param array<string, mixed> $context
     *   Extra metadata (HTTP status code, retry attempt number, etc.).
     *   Implementations may ignore.
     */
    public function recordOutcome(string $status, array $context = []): void;
}
