<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Retry;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Sources\Exceptions\SourceFetchException;

/**
 * Duplicates AI\Manager\ExponentialBackoffRetryPolicy's ALGORITHM
 * (attempt count, base delay, jitter) as a single concrete class — not
 * AI's ARCHITECTURE (no separate RetryPolicyInterface, no pluggable
 * strategy layer). Per the approved decision and ADR-0016: this is a
 * deliberate, bounded, documented exception to "never duplicate
 * infrastructure," not an oversight. If a third module needs the same
 * shape of retry behavior, that's the signal to extract a shared
 * abstraction into Core — not before.
 */
final class SourceRetryExecutor
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 200,
        private readonly int $maxDelayMs = 5000,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     *
     * @throws SourceFetchException The original exception, once retries are exhausted or it's non-retryable.
     */
    public function execute(string $sourceId, callable $call): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $call();
            } catch (SourceFetchException $e) {
                $exhausted = $attempt >= $this->maxAttempts;

                if (!$e->isRetryable() || $exhausted) {
                    throw $e;
                }

                $delayMs = $this->delayMilliseconds($attempt);

                $this->logger->warning('Retrying source fetch {source} (attempt {attempt}/{max}) after {delay}ms: {reason}', [
                    'source'  => $sourceId,
                    'attempt' => $attempt,
                    'max'     => $this->maxAttempts,
                    'delay'   => $delayMs,
                    'reason'  => $e->getMessage(),
                ]);

                usleep($delayMs * 1000);
                $attempt++;
            }
        }
    }

    private function delayMilliseconds(int $attemptNumber): int
    {
        $exponential = $this->baseDelayMs * (2 ** ($attemptNumber - 1));
        $capped = min($exponential, $this->maxDelayMs);
        $jitterRange = (int) ($capped * 0.2);

        return $capped + random_int(-$jitterRange, $jitterRange);
    }
}
