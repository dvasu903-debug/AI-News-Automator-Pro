<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Retry;

use AINewsAutomator\Tests\Workflow\Fakes\FakeLogger;
use AINewsAutomator\Workflow\Entities\WorkflowStepErrorType;
use AINewsAutomator\Workflow\Retry\WorkflowStepException;
use AINewsAutomator\Workflow\Retry\WorkflowStepRetryExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Duplicates Sources\Retry\SourceRetryExecutorTest's shape of coverage
 * per ADR-0016/ADR-0017 (Part 6) — a single concrete class, no
 * RetryPolicyInterface.
 */
final class WorkflowStepRetryExecutorTest extends TestCase
{
    private FakeLogger $logger;
    private WorkflowStepRetryExecutor $executor;

    protected function setUp(): void
    {
        $this->logger = new FakeLogger();
        $this->executor = new WorkflowStepRetryExecutor($this->logger, maxAttempts: 3, baseDelayMs: 0, maxDelayMs: 0);
    }

    public function test_succeeds_immediately_without_retrying(): void
    {
        $calls = 0;
        $result = $this->executor->execute('step', function () use (&$calls) {
            $calls++;
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->logger->countLevel('warning'));
    }

    public function test_retries_a_transient_failure_until_success(): void
    {
        $calls = 0;
        $result = $this->executor->execute('step', function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw new WorkflowStepException('flaky', WorkflowStepErrorType::Transient);
            }
            return 'recovered';
        });

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $calls);
        $this->assertSame(1, $this->logger->countLevel('warning'));
    }

    public function test_exhausts_retries_and_throws(): void
    {
        $calls = 0;

        $this->expectException(WorkflowStepException::class);

        try {
            $this->executor->execute('step', function () use (&$calls) {
                $calls++;
                throw new WorkflowStepException('always flaky', WorkflowStepErrorType::Transient);
            });
        } finally {
            $this->assertSame(3, $calls); // maxAttempts
        }
    }

    public function test_non_retryable_error_type_fails_on_first_attempt(): void
    {
        $calls = 0;

        try {
            $this->executor->execute('step', function () use (&$calls) {
                $calls++;
                throw new WorkflowStepException('bad config', WorkflowStepErrorType::Validation);
            });
            $this->fail('Expected WorkflowStepException.');
        } catch (WorkflowStepException $e) {
            $this->assertSame(1, $calls);
            $this->assertSame(WorkflowStepErrorType::Validation, $e->errorType());
        }
    }

    public function test_unclassified_throwable_defaults_to_unknown_non_retryable(): void
    {
        $calls = 0;

        try {
            $this->executor->execute('step', function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('unexpected');
            });
            $this->fail('Expected WorkflowStepException.');
        } catch (WorkflowStepException $e) {
            $this->assertSame(1, $calls);
            $this->assertSame(WorkflowStepErrorType::Unknown, $e->errorType());
            $this->assertFalse($e->isRetryable());
        }
    }
}
