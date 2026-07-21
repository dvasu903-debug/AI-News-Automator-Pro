<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\DTO;

/**
 * The outcome of ActionInterface::execute(). Exactly one of three shapes:
 * a synchronous success (with an output payload), a synchronous failure
 * (with an error message), or a deferred result (the action has enqueued
 * a background job and will complete later — see the approved Decision
 * 3 async model, §2.3 step 6).
 *
 * Deliberately a single class with named constructors rather than three
 * subclasses — WorkflowRunner branches on outcome() once, and every
 * caller site reads clearly (ActionResult::success(...) /
 * ActionResult::failure(...) / ActionResult::deferred(...)).
 */
final class ActionResult
{
    private function __construct(
        public readonly ActionOutcome $outcome,
        public readonly array $output,
        public readonly ?string $error,
        public readonly ?int $deferredQueueJobId,
    ) {
    }

    /**
     * @param array<string, mixed> $output
     */
    public static function success(array $output = []): self
    {
        return new self(ActionOutcome::Success, $output, null, null);
    }

    public static function failure(string $error): self
    {
        return new self(ActionOutcome::Failure, [], $error, null);
    }

    /**
     * The action has enqueued $queueJobId and will complete asynchronously.
     * WorkflowRunner marks the step Running and returns; resumption
     * happens via the queue-completion listener (see
     * Workflow\Runner\WorkflowRunner::resumeFromQueueJob()).
     */
    public static function deferred(int $queueJobId): self
    {
        return new self(ActionOutcome::Deferred, [], null, $queueJobId);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === ActionOutcome::Success;
    }

    public function isFailure(): bool
    {
        return $this->outcome === ActionOutcome::Failure;
    }

    public function isDeferred(): bool
    {
        return $this->outcome === ActionOutcome::Deferred;
    }

    /** See ActionOutcome::AwaitingApproval's docblock for why this exists. */
    public static function awaitingApproval(): self
    {
        return new self(ActionOutcome::AwaitingApproval, [], null, null);
    }

    public function isAwaitingApproval(): bool
    {
        return $this->outcome === ActionOutcome::AwaitingApproval;
    }
}
