<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Sources;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Sources\Exceptions\SourceFetchException;
use AINewsAutomator\Sources\Retry\SourceFetchErrorType;
use AINewsAutomator\Sources\Retry\SourceRetryExecutor;
use PHPUnit\Framework\TestCase;

final class SourceRetryExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
    }

    private function executor(int $maxAttempts = 3): SourceRetryExecutor
    {
        $logger = new OptionBackedLogger(new CorrelationContext('test'), Environment::Development);
        return new SourceRetryExecutor($logger, $maxAttempts, baseDelayMs: 1, maxDelayMs: 2);
    }

    public function test_returns_on_first_success(): void
    {
        $calls = 0;
        $result = $this->executor()->execute('src-1', function () use (&$calls) {
            $calls++;
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function test_retries_retryable_error_then_succeeds(): void
    {
        $calls = 0;
        $result = $this->executor()->execute('src-1', function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw new SourceFetchException('timeout', SourceFetchErrorType::NetworkTimeout);
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function test_non_retryable_error_is_not_retried(): void
    {
        $calls = 0;

        $this->expectException(SourceFetchException::class);

        try {
            $this->executor()->execute('src-1', function () use (&$calls) {
                $calls++;
                throw new SourceFetchException('not found', SourceFetchErrorType::NotFound);
            });
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function test_robots_disallowed_is_never_retried(): void
    {
        $calls = 0;

        try {
            $this->executor()->execute('src-1', function () use (&$calls) {
                $calls++;
                throw new SourceFetchException('disallowed', SourceFetchErrorType::RobotsDisallowed);
            });
        } catch (SourceFetchException) {
            // expected
        }

        $this->assertSame(1, $calls);
    }

    public function test_exhausted_retries_throws_the_last_exception(): void
    {
        $calls = 0;

        $this->expectException(SourceFetchException::class);

        try {
            $this->executor(maxAttempts: 2)->execute('src-1', function () use (&$calls) {
                $calls++;
                throw new SourceFetchException('server error', SourceFetchErrorType::ServerError);
            });
        } finally {
            $this->assertSame(2, $calls);
        }
    }
}
