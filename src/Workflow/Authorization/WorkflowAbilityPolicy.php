<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Authorization;

use AINewsAutomator\Security\Authorization\AuthorizationContext;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Authorization\PolicyDecision;
use AINewsAutomator\Security\Contracts\PolicyInterface;

/**
 * Adds "workflow.manage", "workflow.approve", and "workflow.view" as
 * real, checkable abilities WITHOUT modifying Security's frozen
 * Capabilities class — the same extension mechanism
 * Research\Authorization\ResearchAbilityPolicy used. Registered under
 * the "security.policies" container tag by WorkflowServiceProvider.
 *
 * Capability mapping per the approved Decision 4:
 *   workflow.manage  -> Capabilities::RUN_PIPELINE
 *   workflow.approve -> Capabilities::APPROVE_CONTENT
 *   workflow.view    -> Capabilities::VIEW_ANALYTICS
 */
final class WorkflowAbilityPolicy implements PolicyInterface
{
    private const POLICY_NAME = 'workflow_ability_policy';

    public const MANAGE  = 'workflow.manage';
    public const APPROVE = 'workflow.approve';
    public const VIEW    = 'workflow.view';

    public function handledAbilities(): array
    {
        return [self::MANAGE, self::APPROVE, self::VIEW];
    }

    public function decide(string $ability, AuthorizationContext $context): PolicyDecision
    {
        $required = match ($ability) {
            self::MANAGE  => Capabilities::RUN_PIPELINE,
            self::APPROVE => Capabilities::APPROVE_CONTENT,
            self::VIEW    => Capabilities::VIEW_ANALYTICS,
            default       => null,
        };

        if ($required === null) {
            return PolicyDecision::abstain(self::POLICY_NAME, 'Ability not handled by this policy.');
        }

        return user_can($context->userId, $required)
            ? PolicyDecision::allow(self::POLICY_NAME)
            : PolicyDecision::deny(self::POLICY_NAME, sprintf('Missing required capability "%s" for ability "%s".', $required, $ability));
    }
}
