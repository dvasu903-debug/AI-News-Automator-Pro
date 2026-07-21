<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

use AINewsAutomator\Workflow\Entities\WorkflowDefinitionVersion;

/**
 * Write-once versioned storage for workflow definitions — Part 1,
 * Option A. Deliberately has NO update()/save()-over-existing-id method;
 * saveNewVersion() is the only write path and always creates a new row.
 * Mirrors AI\Prompt\PromptTemplateRepositoryInterface's write-once shape.
 *
 * `Storage\Contracts\WorkflowRepositoryInterface` / `ana_workflows` is
 * intentionally NOT used anywhere behind this interface — see
 * MODULE_7_WORKFLOW_ENGINE_DESIGN.md Part 1.
 */
interface WorkflowDefinitionRepositoryInterface
{
    /**
     * @throws \AINewsAutomator\Storage\Exceptions\ValidationException If (workflowKey, version) already exists.
     */
    public function saveNewVersion(WorkflowDefinitionVersion $definition): int;

    public function findVersion(string $workflowKey, int $version): ?WorkflowDefinitionVersion;

    /** Highest-versioned definition for this key, or null if none exists. */
    public function latest(string $workflowKey): ?WorkflowDefinitionVersion;

    /**
     * @return list<WorkflowDefinitionVersion> Newest version first.
     */
    public function history(string $workflowKey): array;

    /**
     * @return list<string> Every distinct workflow_key with at least one version.
     */
    public function allKeys(): array;
}
