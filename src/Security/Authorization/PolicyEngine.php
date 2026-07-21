<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

use AINewsAutomator\Security\Contracts\PolicyInterface;

/**
 * Aggregates the decisions of all registered policies for an ability into a
 * single Allow/Deny, following the flow: Permission -> Policy -> Decision.
 * (Audit + Event happen one layer up, in the CapabilityGate, so the engine
 * stays a pure decision function that's trivial to unit test.)
 *
 * Resolution rules, in order:
 *   1. Any explicit Deny -> denied (a single policy can veto).
 *   2. Otherwise, at least one Allow -> allowed.
 *   3. Otherwise (all Abstain, or no policies) -> denied (default-deny).
 *
 * Default-deny is the safe default: an ability nobody explicitly allows is
 * refused rather than accidentally permitted.
 */
final class PolicyEngine
{
    /** @var list<PolicyInterface> */
    private array $policies;

    /**
     * @param iterable<PolicyInterface> $policies
     */
    public function __construct(iterable $policies = [])
    {
        $this->policies = [];
        foreach ($policies as $policy) {
            $this->policies[] = $policy;
        }
    }

    public function addPolicy(PolicyInterface $policy): void
    {
        $this->policies[] = $policy;
    }

    /**
     * @return AuthorizationResult The final decision plus the per-policy
     *                             decisions that produced it (for auditing).
     */
    public function evaluate(string $ability, AuthorizationContext $context): AuthorizationResult
    {
        /** @var list<PolicyDecision> $decisions */
        $decisions = [];
        $sawAllow = false;

        foreach ($this->policies as $policy) {
            if (!$this->handles($policy, $ability)) {
                continue;
            }

            $decision = $policy->decide($ability, $context);
            $decisions[] = $decision;

            if ($decision->outcome === PolicyOutcome::Deny) {
                // Explicit deny short-circuits — veto wins immediately.
                return new AuthorizationResult(false, $ability, $decisions, $decision);
            }

            if ($decision->outcome === PolicyOutcome::Allow) {
                $sawAllow = true;
            }
        }

        if ($sawAllow) {
            return new AuthorizationResult(true, $ability, $decisions, null);
        }

        // Default-deny.
        return new AuthorizationResult(false, $ability, $decisions, null);
    }

    private function handles(PolicyInterface $policy, string $ability): bool
    {
        $handled = $policy->handledAbilities();

        return in_array('*', $handled, true) || in_array($ability, $handled, true);
    }
}
