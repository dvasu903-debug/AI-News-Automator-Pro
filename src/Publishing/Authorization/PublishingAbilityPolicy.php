<?php
/**
 * Adds Publishing's abilities without modifying Security's frozen
 * Capabilities class — the same extension mechanism
 * WorkflowAbilityPolicy/ResearchAbilityPolicy used (see ADR-0018).
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Authorization;

use AINewsAutomator\Security\Authorization\AuthorizationContext;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Authorization\PolicyDecision;
use AINewsAutomator\Security\Contracts\PolicyInterface;

final class PublishingAbilityPolicy implements PolicyInterface
{
    private const POLICY_NAME = 'publishing_ability_policy';

    public const PUBLISH         = 'publishing.publish';
    public const MANAGE_PROFILES = 'publishing.manage_profiles';
    public const VIEW            = 'publishing.view';

    public function handledAbilities(): array
    {
        return [self::PUBLISH, self::MANAGE_PROFILES, self::VIEW];
    }

    public function decide(string $ability, AuthorizationContext $context): PolicyDecision
    {
        $required = match ($ability) {
            self::PUBLISH, self::MANAGE_PROFILES => Capabilities::RUN_PIPELINE,
            self::VIEW => Capabilities::VIEW_ANALYTICS,
            default => null,
        };

        if (null === $required) {
            return PolicyDecision::abstain(self::POLICY_NAME, 'Ability not handled by this policy.');
        }

        return user_can($context->userId, $required)
            ? PolicyDecision::allow(self::POLICY_NAME)
            : PolicyDecision::deny(
                self::POLICY_NAME,
                sprintf('Missing required capability "%s" for ability "%s".', $required, $ability)
            );
    }
}
