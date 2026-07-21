<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\DTO;

/**
 * One step within a WorkflowDefinition's parsed JSON. `condition` is
 * structured data (field/operator/value), never an expression string —
 * see §2.4 and the Part 5 security review. `next`/`onFailureNext`
 * support simple linear-with-branching flow (switch-shaped routing);
 * omitted means "proceed to the next step in array order."
 */
final class StepDefinition
{
    /**
     * @param array<string, mixed> $config Action-specific configuration, passed through to the action untouched.
     * @param array{field: string, operator: string, value: mixed}|null $condition
     */
    public function __construct(
        public readonly string $key,
        public readonly string $action,
        public readonly array $config = [],
        public readonly ?array $condition = null,
        public readonly ?string $next = null,
        public readonly ?string $onFailureNext = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            key: (string) $row['key'],
            action: (string) $row['action'],
            config: is_array($row['config'] ?? null) ? $row['config'] : [],
            condition: is_array($row['condition'] ?? null) ? $row['condition'] : null,
            next: isset($row['next']) ? (string) $row['next'] : null,
            onFailureNext: isset($row['on_failure_next']) ? (string) $row['on_failure_next'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'             => $this->key,
            'action'          => $this->action,
            'config'          => $this->config,
            'condition'       => $this->condition,
            'next'            => $this->next,
            'on_failure_next' => $this->onFailureNext,
        ];
    }
}
