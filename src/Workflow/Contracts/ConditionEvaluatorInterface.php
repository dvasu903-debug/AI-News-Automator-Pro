<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

/**
 * Evaluates a step's structured condition (field/operator/value JSON)
 * against the run's accumulated context. Deliberately NOT an expression
 * language — no eval(), no user-supplied PHP. See §2.4 and the Part 5
 * security review.
 */
interface ConditionEvaluatorInterface
{
    /**
     * @param array<string, mixed>|null $condition Structured condition, or null (always true).
     * @param array<string, mixed> $context Accumulated run context (prior steps' outputs, keyed by step key).
     */
    public function evaluate(?array $condition, array $context): bool;
}
