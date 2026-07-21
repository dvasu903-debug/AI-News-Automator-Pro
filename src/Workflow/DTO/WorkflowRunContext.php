<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\DTO;

/**
 * What an ActionInterface::execute() receives: the run/step identity,
 * this step's own config, and the accumulated output of every prior
 * step in this run (keyed by step key) — the shape the ConditionEvaluator
 * also evaluates conditions against.
 */
final class WorkflowRunContext
{
    /**
     * @param array<string, mixed> $stepConfig This step's own `config` from the definition.
     * @param array<string, array<string, mixed>> $priorStepOutputs Step key => that step's output payload.
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly string $correlationId,
        public readonly array $stepConfig,
        public readonly array $priorStepOutputs,
        public readonly ?int $resumeQueueJobId = null,
    ) {
    }

    /**
     * $default keeps its name deliberately (flagged only because
     * "default" is a PHP soft keyword, not because it's ambiguous or
     * unclear — it's the standard, conventional name for this kind of
     * value). Renaming would be a technically-breaking change for any
     * future named-argument caller for zero readability gain.
     */
    public function priorOutput(string $stepKey, string $field, mixed $default = null): mixed
    {
        return $this->priorStepOutputs[$stepKey][$field] ?? $default;
    }
}
