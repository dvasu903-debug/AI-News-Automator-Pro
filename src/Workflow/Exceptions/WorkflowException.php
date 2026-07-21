<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Exceptions;

/**
 * Base for every Workflow-module exception, with named constructors for
 * the orchestration-level failure cases WorkflowRunner itself throws
 * (as opposed to WorkflowStepException, which is retry-classification-
 * specific and lives in Retry\).
 */
class WorkflowException extends \RuntimeException
{
    public static function definitionNotFound(string $workflowKey, ?int $version): self
    {
        return new self($version === null
            ? sprintf('No workflow definition exists for key "%s".', $workflowKey)
            : sprintf('No version %d of workflow definition "%s" exists.', $version, $workflowKey));
    }

    public static function runNotFound(int $runId): self
    {
        return new self(sprintf('No workflow run found for id %d.', $runId));
    }

    public static function noPendingApproval(int $runId, string $stepKey): self
    {
        return new self(sprintf('No pending approval found for run %d, step "%s".', $runId, $stepKey));
    }

    public static function invalidTransition(string $context): self
    {
        return new self(sprintf('Invalid state transition: %s', $context));
    }
}
