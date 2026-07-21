<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

use AINewsAutomator\Security\Contracts\PolicyInterface;

/**
 * The baseline policy: maps an ability to its WordPress capability and
 * consults user_can(). This is the policy that makes the fine-grained
 * capability system work; other policies (IP allowlist, future 2FA gates)
 * layer on top via the PolicyEngine.
 *
 * Returns Abstain for abilities it doesn't recognize, so an unknown ability
 * isn't silently allowed OR denied by this policy alone — the engine's
 * default-deny handles the terminal decision.
 */
final class DefaultCapabilityPolicy implements PolicyInterface
{
    public function handledAbilities(): array
    {
        return array_keys(Capabilities::abilityMap());
    }

    public function decide(string $ability, AuthorizationContext $context): PolicyDecision
    {
        $map = Capabilities::abilityMap();

        if (!isset($map[$ability])) {
            return PolicyDecision::abstain(self::class, sprintf('Ability "%s" not handled by capability policy.', $ability));
        }

        $capability = $map[$ability];

        if (!$context->isAuthenticated()) {
            return PolicyDecision::deny(self::class, 'Actor is not authenticated.');
        }

        if (user_can($context->userId, $capability)) {
            return PolicyDecision::allow(self::class, sprintf('User has capability "%s".', $capability));
        }

        return PolicyDecision::deny(self::class, sprintf('User lacks capability "%s".', $capability));
    }
}
