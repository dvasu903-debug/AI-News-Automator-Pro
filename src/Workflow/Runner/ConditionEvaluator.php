<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Runner;

use AINewsAutomator\Workflow\Contracts\ConditionEvaluatorInterface;

/**
 * Structured field/operator/value comparison only — no eval(), no
 * user-supplied PHP, no general expression language. See
 * MODULE_7_WORKFLOW_ENGINE_DESIGN.md §2.4 and the Part 5 security
 * review: this is a deliberate, hard security boundary given workflow
 * definitions may eventually be admin-editable JSON.
 *
 * `field` is dot-notation into the run's accumulated context, e.g.
 * "research.overallConfidence" reads $context['research']['overallConfidence'].
 */
final class ConditionEvaluator implements ConditionEvaluatorInterface
{
    private const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'exists', 'not_exists'];

    public function evaluate(?array $condition, array $context): bool
    {
        if ($condition === null) {
            return true;
        }

        $field = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? 'eq');
        $expected = $condition['value'] ?? null;

        if ($field === '' || !in_array($operator, self::OPERATORS, true)) {
            // A malformed condition never silently passes — fail closed.
            return false;
        }

        $exists = $this->fieldExists($field, $context);
        $actual = $exists ? $this->resolveField($field, $context) : null;

        return match ($operator) {
            'exists'     => $exists,
            'not_exists' => !$exists,
            'eq'         => $exists && $actual === $expected,
            'neq'        => !$exists || $actual !== $expected,
            'gt'         => $exists && is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte'        => $exists && is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt'         => $exists && is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte'        => $exists && is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'in'         => $exists && is_array($expected) && in_array($actual, $expected, true),
            'not_in'     => !$exists || (is_array($expected) && !in_array($actual, $expected, true)),
            default      => false,
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function fieldExists(string $field, array $context): bool
    {
        $cursor = $context;

        foreach (explode('.', $field) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveField(string $field, array $context): mixed
    {
        $cursor = $context;

        foreach (explode('.', $field) as $segment) {
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
