<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\Exceptions\AIException;

/**
 * Decides whether and how long to wait before retrying a failed call.
 * Consulted by RetryExecutor — never retries every failure, only what
 * the exception's AIErrorType classifies as retryable AND what this
 * policy's own attempt-budget allows.
 */
interface RetryPolicyInterface
{
    public function shouldRetry(AIException $exception, int $attemptNumber): bool;

    public function delayMilliseconds(int $attemptNumber): int;

    public function maxAttempts(): int;
}
