<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

/**
 * The outcome of a PolicyEngine evaluation: the boolean result, the ability
 * evaluated, every policy decision that contributed, and (if denied by an
 * explicit veto) the deciding policy. The gate uses the decisions list to
 * write a meaningful audit record explaining why access was granted or
 * refused.
 */
final class AuthorizationResult
{
    /**
     * @param list<PolicyDecision> $decisions
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $ability,
        public readonly array $decisions,
        public readonly ?PolicyDecision $decidingDenial,
    ) {
    }

    public function reasonSummary(): string
    {
        if ($this->decidingDenial !== null) {
            return $this->decidingDenial->reason;
        }

        if ($this->allowed) {
            foreach ($this->decisions as $decision) {
                if ($decision->outcome === PolicyOutcome::Allow) {
                    return $decision->reason;
                }
            }
            return 'Allowed.';
        }

        return 'No policy granted the ability (default deny).';
    }
}
