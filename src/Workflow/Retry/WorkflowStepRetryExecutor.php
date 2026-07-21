<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Retry;

use AINewsAutomator\Core\Contracts\LoggerInterface;

/**
 * Duplicates Sources\Retry\SourceRetryExecutor's ALGORITHM (attempt
 * count, base delay, jitter-free exponential backoff) as a single
 * concrete class — not AI's or Sources' ARCHITECTURE (no
 * RetryPolicyInterface abstraction layer). This is the THIRD instance
 * ADR-0016 anticipated and ADR-0017 already pre-approved the deferral
 * for (Part 6 of the Module 7 design) — no new ADR needed, no
 * modification to AI\Manager\RetryExecutor or
 * Sources\Retry\SourceRetryExecutor.
 *
 * Note: the design doc's folder listing (§2.1) also mentioned a
 * Contracts\WorkflowRetryPolicyInterface.php, which contradicts its own
 * Part 6 ("no RetryPolicyInterface abstraction layer"). Resolved in
 * favor of Part 6 and the actual SourceRetryExecutor precedent — no
 * such interface exists in this module.
 */
final class WorkflowStepRetryExecutor
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
     * @throws WorkflowStepException The classified exception, once retries are exhausted or it's non-retryable.
     */
    public function execute(string $stepKey, callable $call): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $call();
            } catch (\Throwable $raw) {
                $e = WorkflowStepException::fromThrowable($raw);
                $exhausted = $attempt >= $this->maxAttempts;

                if (!$e->isRetryable() || $exhausted) {
                    throw $e;
                }

                $delayMs = $this->delayMilliseconds($attempt);

                $this->logger->warning('Retrying workflow step {step} (attempt {attempt}/{max}) after {delay}ms: {reason}', [
                    'step'    => $stepKey,
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

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    private function delayMilliseconds(int $attemptNumber): int
    {
        $exponential = $this->baseDelayMs * (2 ** ($attemptNumber - 1));

        return min($exponential, $this->maxDelayMs);
    }
}
