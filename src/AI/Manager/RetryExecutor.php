<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Manager;

use AINewsAutomator\AI\Contracts\RetryPolicyInterface;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\AI\Exceptions\ProviderUnavailableException;
use AINewsAutomator\Core\Contracts\LoggerInterface;

/**
 * Runs a provider call, retrying according to RetryPolicyInterface.
 * Never retries a non-retryable AIException (validation, auth, quota,
 * unsupported-capability) even once — only genuinely transient failures
 * (rate-limited, provider outage) get a second attempt.
 */
final class RetryExecutor
{
    public function __construct(
        private readonly RetryPolicyInterface $policy,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     *
     * @throws AIException The original exception, if non-retryable.
     * @throws ProviderUnavailableException If retries are exhausted.
     */
    public function execute(string $providerId, callable $call): mixed
    {
        $attempt = 1;
        $lastException = null;

        while (true) {
            try {
                return $call();
            } catch (AIException $e) {
                $lastException = $e;

                if (!$this->policy->shouldRetry($e, $attempt)) {
                    if ($e->isRetryable()) {
                        // Retryable in principle, but the policy's attempt
                        // budget is exhausted — surface as unavailable so
                        // FailoverChain gets a chance, rather than the raw
                        // classified exception.
                        throw ProviderUnavailableException::afterRetries($providerId, $attempt, $e);
                    }

                    throw $e;
                }

                $delayMs = $this->policy->delayMilliseconds($attempt);

                $this->logger->warning('Retrying {provider} (attempt {attempt}/{max}) after {delay}ms: {reason}', [
                    'provider' => $providerId,
                    'attempt'  => $attempt,
                    'max'      => $this->policy->maxAttempts(),
                    'delay'    => $delayMs,
                    'reason'   => $e->getMessage(),
                ]);

                usleep($delayMs * 1000);
                $attempt++;
            }
        }
    }
}
