<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

/**
 * A row in `ana_workflow_definitions` — one immutable, write-once
 * version of a workflow's definition. Mirrors AI\Prompt\PromptTemplate's
 * write-once shape (Part 1, Option A), except version is a plain
 * incrementing integer per workflow_key rather than semver — nothing in
 * the approved design calls for semver here, and the schema's own
 * UNIQUE(workflow_key, version) implies a simple integer sequence.
 */
final class WorkflowDefinitionVersion
{
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $workflowKey,
        public readonly int $version,
        public readonly array $definition,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            workflowKey: (string) $row['workflow_key'],
            version: (int) $row['version'],
            definition: is_string($row['definition'] ?? null) ? (json_decode($row['definition'], true) ?: []) : [],
            createdAt: \AINewsAutomator\Storage\Entities\EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'workflow_key' => $this->workflowKey,
            'version'      => $this->version,
            'definition'   => wp_json_encode($this->definition) ?: '{}',
            'created_at'   => \AINewsAutomator\Storage\Entities\EntityDates::toMysql($this->createdAt),
        ];
    }
}
