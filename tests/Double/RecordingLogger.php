<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Double;

use Psr\Log\AbstractLogger;

/**
 * Keeps what was logged so a test can read it.
 *
 * Some of this plugin's behaviour has no other observable form. A delivery for an event type this
 * store does not act on is *supposed* to change nothing — so the only thing separating "handled
 * correctly" from "silently dropped" is the log line, and a test that cannot read it cannot tell
 * those apart.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    public function hasRecordContaining(string $fragment): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $fragment)) {
                return true;
            }
        }

        return false;
    }
}
