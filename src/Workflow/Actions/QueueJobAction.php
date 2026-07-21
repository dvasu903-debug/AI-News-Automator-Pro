<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Actions;

use AINewsAutomator\Storage\Contracts\QueueRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

/**
 * Generic "enqueue any Storage job type" action — lets a workflow
 * definition delegate a step to any existing queue-backed job type
 * (e.g. a future Publishing job) without Workflow needing to know
 * anything about what that job type does. Always deferred: enqueueing
 * work and waiting for it is the entire point of this action, so it
 * always returns ActionResult::deferred(), never success/failure
 * directly (a failure to even enqueue is the one case that returns
 * failure — see below).
 */
final class QueueJobAction implements ActionInterface
{
    public function __construct(private readonly QueueRepositoryInterface $queue)
    {
    }

    public function type(): string
    {
        return 'queue_job';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $jobType = (string) ($context->stepConfig['job_type'] ?? '');

        if ($jobType === '') {
            return ActionResult::failure('queue_job action requires a "job_type" in step config.');
        }

        $payload = is_array($context->stepConfig['payload'] ?? null) ? $context->stepConfig['payload'] : [];
        $priority = (int) ($context->stepConfig['priority'] ?? 100);

        try {
            $jobId = $this->queue->enqueue($jobType, $payload, $priority, null, $context->correlationId);
        } catch (\Throwable $e) {
            return ActionResult::failure(sprintf('Failed to enqueue job type "%s": %s', $jobType, $e->getMessage()));
        }

        return ActionResult::deferred($jobId);
    }
}
