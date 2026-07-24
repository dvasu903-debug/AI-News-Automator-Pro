<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Authorization\ResearchAbilityPolicy;
use AINewsAutomator\Security\Authorization\AuthorizationContext;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Authorization\PolicyOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 4: this
 * authorization-critical class previously had zero test coverage.
 */
final class ResearchAbilityPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_user_caps'] = [];
    }

    public function test_handled_abilities_lists_both_abilities(): void
    {
        $policy = new ResearchAbilityPolicy();
        $this->assertSame(['research.manage', 'research.view'], $policy->handledAbilities());
    }

    public function test_unrecognized_ability_abstains(): void
    {
        $policy = new ResearchAbilityPolicy();
        $context = new AuthorizationContext(1, '127.0.0.1');

        $decision = $policy->decide('some.other.ability', $context);

        $this->assertSame(PolicyOutcome::Abstain, $decision->outcome);
    }

    public function test_manage_allowed_when_user_has_run_pipeline_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][42] = [Capabilities::RUN_PIPELINE];
        $policy = new ResearchAbilityPolicy();
        $context = new AuthorizationContext(42, '127.0.0.1');

        $decision = $policy->decide(ResearchAbilityPolicy::MANAGE, $context);

        $this->assertSame(PolicyOutcome::Allow, $decision->outcome);
    }

    public function test_manage_denied_when_user_lacks_run_pipeline_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][42] = []; // no capabilities
        $policy = new ResearchAbilityPolicy();
        $context = new AuthorizationContext(42, '127.0.0.1');

        $decision = $policy->decide(ResearchAbilityPolicy::MANAGE, $context);

        $this->assertSame(PolicyOutcome::Deny, $decision->outcome);
    }

    public function test_manage_denied_for_unrelated_capability(): void
    {
        // Having VIEW_ANALYTICS (the VIEW ability's own required capability)
        // must not satisfy MANAGE — the two abilities are independent.
        $GLOBALS['__ana_test_user_caps'][42] = [Capabilities::VIEW_ANALYTICS];
        $policy = new ResearchAbilityPolicy();
        $context = new AuthorizationContext(42, '127.0.0.1');

        $decision = $policy->decide(ResearchAbilityPolicy::MANAGE, $context);

        $this->assertSame(PolicyOutcome::Deny, $decision->outcome);
    }

    public function test_view_allowed_when_user_has_view_analytics_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][7] = [Capabilities::VIEW_ANALYTICS];
        $policy = new ResearchAbilityPolicy();
        $context = new AuthorizationContext(7, '127.0.0.1');

        $decision = $policy->decide(ResearchAbilityPolicy::VIEW, $context);

        $this->assertSame(PolicyOutcome::Allow, $decision->outcome);
    }

    public function test_view_denied_when_user_lacks_view_analytics_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][7] = [];
        $policy = new ResearchAbilityPolicy();
        $context = new AuthorizationContext(7, '127.0.0.1');

        $decision = $policy->decide(ResearchAbilityPolicy::VIEW, $context);

        $this->assertSame(PolicyOutcome::Deny, $decision->outcome);
    }

    public function test_denial_reason_names_the_missing_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][42] = [];
        $policy = new ResearchAbilityPolicy();
        $context = new AuthorizationContext(42, '127.0.0.1');

        $decision = $policy->decide(ResearchAbilityPolicy::MANAGE, $context);

        $this->assertStringContainsString(Capabilities::RUN_PIPELINE, $decision->reason);
    }
}
