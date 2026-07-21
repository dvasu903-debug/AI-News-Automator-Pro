<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Manager;

use AINewsAutomator\AI\Contracts\RetryPolicyInterface;
use AINewsAutomator\AI\Exceptions\AIException;

/**
 * Standard exponential backoff with jitter. shouldRetry() consults the
 * exception's own AIErrorType classification first — "do not retry every
 * failure" is enforced here, not left to the caller's discretion.
 */
final class ExponentialBackoffRetryPolicy implements RetryPolicyInterface
{
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 200,
        private readonly int $maxDelayMs = 5000,
    ) {
    }

    public function shouldRetry(AIException $exception, int $attemptNumber): bool
    {
        if (!$exception->isRetryable()) {
            return false;
        }

        return $attemptNumber < $this->maxAttempts;
    }

    public function delayMilliseconds(int $attemptNumber): int
    {
        $exponential = $this->baseDelayMs * (2 ** ($attemptNumber - 1));
        $capped = min($exponential, $this->maxDelayMs);

        // Jitter: +/- 20%, avoids a thundering-herd of simultaneously
        // retrying requests all waking up at the exact same millisecond.
        $jitterRange = (int) ($capped * 0.2);

        return $capped + random_int(-$jitterRange, $jitterRange);
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
