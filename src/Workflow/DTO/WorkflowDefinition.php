<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\DTO;

/**
 * Parsed form of a WorkflowDefinitionVersion's JSON `definition` column —
 * what the Runner actually walks. Kept separate from the Entities\
 * WorkflowDefinitionVersion (the storage row) so parsing/validation
 * happens once, at the boundary, not scattered through the Runner.
 */
final class WorkflowDefinition
{
    /**
     * @param list<StepDefinition> $steps
     * @param array<string, mixed> $triggerConfig
     */
    public function __construct(
        public readonly string $workflowKey,
        public readonly int $version,
        public readonly array $steps,
        public readonly string $triggerType,
        public readonly array $triggerConfig = [],
    ) {
    }

    /**
     * @param array<string, mixed> $definition Raw decoded JSON.
     */
    public static function fromDecoded(string $workflowKey, int $version, array $definition): self
    {
        $steps = array_map(
            static fn (array $step): StepDefinition => StepDefinition::fromArray($step),
            is_array($definition['steps'] ?? null) ? $definition['steps'] : []
        );

        $trigger = is_array($definition['trigger'] ?? null) ? $definition['trigger'] : [];

        return new self(
            workflowKey: $workflowKey,
            version: $version,
            steps: array_values($steps),
            triggerType: (string) ($trigger['type'] ?? 'manual'),
            triggerConfig: is_array($trigger['config'] ?? null) ? $trigger['config'] : [],
        );
    }

    public function stepByKey(string $key): ?StepDefinition
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        return null;
    }

    public function firstStep(): ?StepDefinition
    {
        return $this->steps[0] ?? null;
    }

    /** Returns the step immediately after $key in array order, or null if $key is last/not found. */
    public function stepAfter(string $key): ?StepDefinition
    {
        $found = false;

        foreach ($this->steps as $step) {
            if ($found) {
                return $step;
            }

            if ($step->key === $key) {
                $found = true;
            }
        }

        return null;
    }
}
