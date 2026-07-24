<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Fakes;

use AINewsAutomator\Core\Contracts\LoggerInterface;

/**
 * Records every log call for assertions instead of writing anywhere,
 * so tests can assert "a warning was logged for this retry" without a
 * real Logger implementation (which is Storage-backed and out of
 * scope for a pure unit test).
 */
final class FakeLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function countLevel(string $level): int
    {
        return count(array_filter($this->entries, static fn (array $e): bool => $e['level'] === $level));
    }
}
