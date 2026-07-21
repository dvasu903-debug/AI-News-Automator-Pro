<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

/**
 * A single policy's decision plus a human-readable reason (recorded in the
 * audit log so a denial is explainable after the fact).
 */
final class PolicyDecision
{
    private function __construct(
        public readonly PolicyOutcome $outcome,
        public readonly string $reason,
        public readonly string $policyName,
    ) {
    }

    public static function allow(string $policyName, string $reason = ''): self
    {
        return new self(PolicyOutcome::Allow, $reason, $policyName);
    }

    public static function deny(string $policyName, string $reason = ''): self
    {
        return new self(PolicyOutcome::Deny, $reason, $policyName);
    }

    public static function abstain(string $policyName, string $reason = ''): self
    {
        return new self(PolicyOutcome::Abstain, $reason, $policyName);
    }
}
