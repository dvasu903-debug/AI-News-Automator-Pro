<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Authorization;

use AINewsAutomator\Security\Authorization\AuthorizationContext;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Authorization\PolicyDecision;
use AINewsAutomator\Security\Contracts\PolicyInterface;

/**
 * Adds "research.manage" and "research.view" as real, checkable
 * abilities WITHOUT modifying Security's frozen Capabilities class —
 * exactly the extension mechanism Module 2 built for this purpose.
 * Registered under the "security.policies" container tag (see
 * ResearchServiceProvider); PolicyEngine picks it up automatically.
 *
 * Maps onto existing WordPress capabilities rather than inventing a new
 * one: research.manage requires Capabilities::RUN_PIPELINE (research is
 * a pipeline stage), research.view requires Capabilities::VIEW_ANALYTICS
 * (viewing research output is an analytics-adjacent concern) — both
 * capability constants already existed before this module, chosen for
 * their closest semantic fit rather than defined fresh.
 */
final class ResearchAbilityPolicy implements PolicyInterface
{
    private const POLICY_NAME = 'research_ability_policy';

    public const MANAGE = 'research.manage';
    public const VIEW = 'research.view';

    public function handledAbilities(): array
    {
        return [self::MANAGE, self::VIEW];
    }

    public function decide(string $ability, AuthorizationContext $context): PolicyDecision
    {
        $required = match ($ability) {
            self::MANAGE => Capabilities::RUN_PIPELINE,
            self::VIEW   => Capabilities::VIEW_ANALYTICS,
            default      => null,
        };

        if ($required === null) {
            return PolicyDecision::abstain(self::POLICY_NAME, 'Ability not handled by this policy.');
        }

        return user_can($context->userId, $required)
            ? PolicyDecision::allow(self::POLICY_NAME)
            : PolicyDecision::deny(self::POLICY_NAME, sprintf('Missing required capability "%s" for ability "%s".', $required, $ability));
    }
}
