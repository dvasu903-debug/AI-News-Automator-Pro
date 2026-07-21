<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Runner;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Storage\Contracts\JobHistoryRepositoryInterface;
use AINewsAutomator\Storage\Events\JobCompletedEvent;
use AINewsAutomator\Storage\Events\JobFailedEvent;

/**
 * Bridges Storage's existing queue-completion events to
 * WorkflowRunner::resumeFromQueueJob() — this is the "existing
 * job-completion mechanism" reused per Decision 3, not a second async
 * framework. Registered as a listener on Core's shared
 * EventDispatcherInterface by WorkflowServiceProvider::boot(), exactly
 * like every other module's cross-module event subscription.
 *
 * Neither JobCompletedEvent nor JobFailedEvent carries the job's result
 * payload directly (the queue row is already deleted by the time either
 * event dispatches — see QueueRepository::markSuccess()/markFailure()),
 * so this listener looks the payload up via
 * JobHistoryRepositoryInterface::find(), reusing that repository's
 * existing read path rather than duplicating it.
 */
final class QueueCompletionListener
{
    public function __construct(
        private readonly WorkflowRunner $runner,
        private readonly JobHistoryRepositoryInterface $jobHistory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onJobCompleted(JobCompletedEvent $event): void
    {
        $entry = $this->jobHistory->find($event->jobId);

        $this->runner->resumeFromQueueJob(
            queueJobId: $event->jobId,
            succeeded: true,
            output: $entry?->result ?? [],
        );
    }

    public function onJobFailed(JobFailedEvent $event): void
    {
        if ($event->willRetry) {
            // Storage's own queue will retry this job — our deferred
            // step stays Deferred until a terminal completion/failure
            // event arrives. See class docblock.
            return;
        }

        $entry = $this->jobHistory->find($event->jobId);

        $this->runner->resumeFromQueueJob(
            queueJobId: $event->jobId,
            succeeded: false,
            output: [],
            error: $entry?->error ?? $event->error,
        );
    }
}
