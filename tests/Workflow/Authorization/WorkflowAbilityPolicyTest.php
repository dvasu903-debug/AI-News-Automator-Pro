<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Authorization;

use AINewsAutomator\Security\Authorization\AuthorizationContext;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Authorization\PolicyOutcome;
use AINewsAutomator\Workflow\Authorization\WorkflowAbilityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the approved Decision 4 capability mapping exactly, and that
 * the policy never touches Security's frozen Capabilities class (it
 * only reads existing constants from it).
 */
final class WorkflowAbilityPolicyTest extends TestCase
{
    private WorkflowAbilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new WorkflowAbilityPolicy();
        $GLOBALS['__ana_test_user_caps'] = [];
    }

    public function test_handled_abilities(): void
    {
        $this->assertSame(
            ['workflow.manage', 'workflow.approve', 'workflow.view'],
            $this->policy->handledAbilities()
        );
    }

    public function test_manage_requires_run_pipeline_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][5] = [Capabilities::RUN_PIPELINE];
        $context = new AuthorizationContext(5, '127.0.0.1');

        $decision = $this->policy->decide(WorkflowAbilityPolicy::MANAGE, $context);

        $this->assertSame(PolicyOutcome::Allow, $decision->outcome);
    }

    public function test_approve_requires_approve_content_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][5] = [Capabilities::RUN_PIPELINE]; // has manage, not approve

        $decision = $this->policy->decide(WorkflowAbilityPolicy::APPROVE, new AuthorizationContext(5, '127.0.0.1'));

        $this->assertSame(PolicyOutcome::Deny, $decision->outcome);
    }

    public function test_view_requires_view_analytics_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][5] = [Capabilities::VIEW_ANALYTICS];

        $decision = $this->policy->decide(WorkflowAbilityPolicy::VIEW, new AuthorizationContext(5, '127.0.0.1'));

        $this->assertSame(PolicyOutcome::Allow, $decision->outcome);
    }

    public function test_unhandled_ability_abstains(): void
    {
        $decision = $this->policy->decide('something.else', new AuthorizationContext(5, '127.0.0.1'));

        $this->assertSame(PolicyOutcome::Abstain, $decision->outcome);
    }
}
