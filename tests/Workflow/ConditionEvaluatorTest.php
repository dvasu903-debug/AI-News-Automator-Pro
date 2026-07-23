<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow;

use AINewsAutomator\Workflow\Runner\ConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * §2.4 / Part 5 security review: structured field/operator/value only,
 * no expression language, malformed conditions fail closed.
 */
final class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ConditionEvaluator();
    }

    public function test_null_condition_is_always_true(): void
    {
        $this->assertTrue($this->evaluator->evaluate(null, []));
    }

    public function test_eq_operator(): void
    {
        $context = ['step' => ['status' => 'approved']];
        $this->assertTrue($this->evaluator->evaluate(['field' => 'step.status', 'operator' => 'eq', 'value' => 'approved'], $context));
        $this->assertFalse($this->evaluator->evaluate(['field' => 'step.status', 'operator' => 'eq', 'value' => 'rejected'], $context));
    }

    public function test_numeric_comparisons(): void
    {
        $context = ['research' => ['confidence' => 0.8]];

        $this->assertTrue($this->evaluator->evaluate(['field' => 'research.confidence', 'operator' => 'gte', 'value' => 0.8], $context));
        $this->assertTrue($this->evaluator->evaluate(['field' => 'research.confidence', 'operator' => 'gt', 'value' => 0.5], $context));
        $this->assertFalse($this->evaluator->evaluate(['field' => 'research.confidence', 'operator' => 'lt', 'value' => 0.5], $context));
    }

    public function test_exists_and_not_exists(): void
    {
        $context = ['a' => ['b' => 1]];

        $this->assertTrue($this->evaluator->evaluate(['field' => 'a.b', 'operator' => 'exists'], $context));
        $this->assertFalse($this->evaluator->evaluate(['field' => 'a.c', 'operator' => 'exists'], $context));
        $this->assertTrue($this->evaluator->evaluate(['field' => 'a.c', 'operator' => 'not_exists'], $context));
    }

    public function test_in_and_not_in(): void
    {
        $context = ['status' => 'approved'];

        $this->assertTrue($this->evaluator->evaluate(['field' => 'status', 'operator' => 'in', 'value' => ['approved', 'pending']], $context));
        $this->assertFalse($this->evaluator->evaluate(['field' => 'status', 'operator' => 'in', 'value' => ['rejected']], $context));
    }

    public function test_missing_field_fails_closed_not_true(): void
    {
        $this->assertFalse($this->evaluator->evaluate(['field' => '', 'operator' => 'eq', 'value' => 1], []));
    }

    public function test_unknown_operator_fails_closed(): void
    {
        $this->assertFalse($this->evaluator->evaluate(['field' => 'a', 'operator' => 'eval_and_run_shellcode', 'value' => 1], ['a' => 1]));
    }
}
