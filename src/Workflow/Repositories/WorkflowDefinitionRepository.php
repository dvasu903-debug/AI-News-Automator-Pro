<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Repositories;

use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;
use AINewsAutomator\Workflow\Contracts\WorkflowDefinitionRepositoryInterface;
use AINewsAutomator\Workflow\Entities\WorkflowDefinitionVersion;

/**
 * Persists to a Workflow-owned table (`ana_workflow_definitions`),
 * reusing Storage's AbstractRepository directly (ADR-0006 — "Storage is
 * frozen from modification, not from reuse"). Enforces write-once
 * versioning: saveNewVersion() refuses a duplicate (workflow_key,
 * version) pair, mirroring AI\Prompt\PromptTemplateRepository exactly —
 * except ordering uses a plain integer comparison, not version_compare(),
 * since workflow versions are a simple incrementing sequence, not semver.
 *
 * @extends AbstractRepository<WorkflowDefinitionVersion>
 */
final class WorkflowDefinitionRepository extends AbstractRepository implements WorkflowDefinitionRepositoryInterface
{
    protected function table(): string
    {
        return 'workflow_definitions';
    }

    protected function hydrate(array $row): WorkflowDefinitionVersion
    {
        return WorkflowDefinitionVersion::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var WorkflowDefinitionVersion $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var WorkflowDefinitionVersion $entity */
        $errors = [];

        if (trim($entity->workflowKey) === '') {
            $errors['workflow_key'] = 'Workflow key is required.';
        }

        if ($entity->version < 1) {
            $errors['version'] = 'Version must be a positive integer.';
        }

        if ($entity->definition === []) {
            $errors['definition'] = 'Definition must not be empty.';
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $errors is used to build an internal validation-exception message only, never echoed as HTML.
            throw new ValidationException($errors, 'Workflow definition failed validation.');
        }
    }

    public function saveNewVersion(WorkflowDefinitionVersion $definition): int
    {
        if ($this->findVersion($definition->workflowKey, $definition->version) !== null) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $definition's properties are used to build an internal validation-exception message only, never echoed as HTML. Disabled for this one multi-line statement rather than a single-line ignore, since the flagged variables land on different physical lines within it.
            throw new ValidationException(
                ['version' => sprintf(
                    'Version %d of workflow "%s" already exists — definitions are write-once.',
                    $definition->version,
                    $definition->workflowKey
                )],
                'Cannot overwrite an existing workflow definition version.'
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return $this->insertRow($definition);
    }

    public function findVersion(string $workflowKey, int $version): ?WorkflowDefinitionVersion
    {
        $row = $this->connection->newQuery($this->table())
            ->whereAll([Filter::equals('workflow_key', $workflowKey), Filter::equals('version', $version)])
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function latest(string $workflowKey): ?WorkflowDefinitionVersion
    {
        $versions = $this->allVersionsSorted($workflowKey);

        return $versions[0] ?? null;
    }

    public function history(string $workflowKey): array
    {
        return $this->allVersionsSorted($workflowKey);
    }

    public function allKeys(): array
    {
        $rows = $this->connection->newQuery($this->table())->get();

        $keys = [];
        foreach ($rows as $row) {
            $keys[(string) $row['workflow_key']] = true;
        }

        return array_keys($keys);
    }

    /**
     * @return list<WorkflowDefinitionVersion> Newest version first.
     */
    private function allVersionsSorted(string $workflowKey): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('workflow_key', $workflowKey))
            ->get();

        $versions = array_map(fn (array $row) => $this->hydrate($row), $rows);

        usort($versions, static fn (WorkflowDefinitionVersion $a, WorkflowDefinitionVersion $b): int => $b->version <=> $a->version);

        return $versions;
    }
}
