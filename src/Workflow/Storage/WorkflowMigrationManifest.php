<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Storage;

use AINewsAutomator\Workflow\Storage\Migrations\Migration_20260715400001_CreateWorkflowDefinitionsTable;
use AINewsAutomator\Workflow\Storage\Migrations\Migration_20260715400002_CreateWorkflowRunsTable;
use AINewsAutomator\Workflow\Storage\Migrations\Migration_20260715400003_CreateWorkflowStepResultsTable;
use AINewsAutomator\Workflow\Storage\Migrations\Migration_20260715400004_CreateWorkflowApprovalsTable;

/**
 * Workflow's own explicit, ordered migration list — mirrors AI's,
 * Sources', and Research's manifest pattern exactly (ADR-0006). Applied
 * through the same shared MigrationRunner singleton Storage registered;
 * no new runner instance needed.
 */
final class WorkflowMigrationManifest
{
    /**
     * @return list<\AINewsAutomator\Storage\Contracts\MigrationInterface>
     */
    public static function migrations(): array
    {
        return [
            new Migration_20260715400001_CreateWorkflowDefinitionsTable(),
            new Migration_20260715400002_CreateWorkflowRunsTable(),
            new Migration_20260715400003_CreateWorkflowStepResultsTable(),
            new Migration_20260715400004_CreateWorkflowApprovalsTable(),
        ];
    }
}
