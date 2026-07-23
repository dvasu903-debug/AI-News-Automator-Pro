<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\Exceptions\AIErrorType;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\AI\Exceptions\ProviderUnavailableException;
use AINewsAutomator\AI\Manager\ExponentialBackoffRetryPolicy;
use AINewsAutomator\AI\Manager\RetryExecutor;
use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use PHPUnit\Framework\TestCase;

final class RetryExecutorTest extends TestCase
{
    private function executor(int $maxAttempts = 3): RetryExecutor
    {
        $logger = new OptionBackedLogger(new CorrelationContext('test'), Environment::Development);
        return new RetryExecutor(new ExponentialBackoffRetryPolicy($maxAttempts, baseDelayMs: 1, maxDelayMs: 2), $logger);
    }

    public function test_returns_result_on_first_success(): void
    {
        $calls = 0;
        $result = $this->executor()->execute('p', function () use (&$calls) {
            $calls++;
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function test_retries_retryable_error_then_succeeds(): void
    {
        $calls = 0;
        $result = $this->executor()->execute('p', function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw new AIException('outage', AIErrorType::ProviderOutage, 'p');
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function test_non_retryable_error_throws_immediately(): void
    {
        $calls = 0;

        $this->expectException(AIException::class);

        try {
            $this->executor()->execute('p', function () use (&$calls) {
                $calls++;
                throw new AIException('bad request', AIErrorType::Validation, 'p');
            });
        } finally {
            $this->assertSame(1, $calls, 'Non-retryable errors must not be retried.');
        }
    }

    public function test_exhausted_retries_throw_provider_unavailable(): void
    {
        $calls = 0;

        $this->expectException(ProviderUnavailableException::class);

        try {
            $this->executor(maxAttempts: 2)->execute('p', function () use (&$calls) {
                $calls++;
                throw new AIException('outage', AIErrorType::ProviderOutage, 'p');
            });
        } finally {
            $this->assertSame(2, $calls);
        }
    }

    public function test_authentication_error_is_never_retried(): void
    {
        $calls = 0;

        try {
            $this->executor()->execute('p', function () use (&$calls) {
                $calls++;
                throw new AIException('bad key', AIErrorType::Authentication, 'p');
            });
        } catch (AIException) {
            // expected
        }

        $this->assertSame(1, $calls);
    }

    public function test_quota_error_is_never_retried(): void
    {
        $calls = 0;

        try {
            $this->executor()->execute('p', function () use (&$calls) {
                $calls++;
                throw new AIException('quota exceeded', AIErrorType::Quota, 'p');
            });
        } catch (AIException) {
            // expected
        }

        $this->assertSame(1, $calls);
    }

    public function test_rate_limited_error_is_retried(): void
    {
        $calls = 0;
        $result = $this->executor()->execute('p', function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw new AIException('rate limited', AIErrorType::RateLimited, 'p');
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }
}
