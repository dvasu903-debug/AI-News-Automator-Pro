<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Authorization;

use AINewsAutomator\Publishing\Authorization\PublishingAbilityPolicy;
use AINewsAutomator\Security\Authorization\AuthorizationContext;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Authorization\PolicyOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ADR-0018's capability mapping exactly, and that the policy
 * never touches Security's frozen Capabilities class (it only reads
 * existing constants from it) — same discipline as
 * WorkflowAbilityPolicyTest.
 */
final class PublishingAbilityPolicyTest extends TestCase
{
    private PublishingAbilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PublishingAbilityPolicy();
        $GLOBALS['__ana_test_user_caps'] = [];
    }

    public function test_handled_abilities(): void
    {
        $this->assertSame(
            ['publishing.publish', 'publishing.manage_profiles', 'publishing.view'],
            $this->policy->handledAbilities()
        );
    }

    public function test_publish_requires_run_pipeline_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][5] = [Capabilities::RUN_PIPELINE];

        $decision = $this->policy->decide(PublishingAbilityPolicy::PUBLISH, new AuthorizationContext(5, '127.0.0.1'));

        $this->assertSame(PolicyOutcome::Allow, $decision->outcome);
    }

    public function test_manage_profiles_requires_run_pipeline_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][5] = [Capabilities::RUN_PIPELINE];

        $decision = $this->policy->decide(PublishingAbilityPolicy::MANAGE_PROFILES, new AuthorizationContext(5, '127.0.0.1'));

        $this->assertSame(PolicyOutcome::Allow, $decision->outcome);
    }

    public function test_view_requires_view_analytics_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][5] = [Capabilities::VIEW_ANALYTICS];

        $decision = $this->policy->decide(PublishingAbilityPolicy::VIEW, new AuthorizationContext(5, '127.0.0.1'));

        $this->assertSame(PolicyOutcome::Allow, $decision->outcome);
    }

    public function test_view_denied_without_capability(): void
    {
        $GLOBALS['__ana_test_user_caps'][5] = [Capabilities::RUN_PIPELINE]; // has publish, not view

        $decision = $this->policy->decide(PublishingAbilityPolicy::VIEW, new AuthorizationContext(5, '127.0.0.1'));

        $this->assertSame(PolicyOutcome::Deny, $decision->outcome);
    }

    public function test_unhandled_ability_abstains(): void
    {
        $decision = $this->policy->decide('something.else', new AuthorizationContext(5, '127.0.0.1'));

        $this->assertSame(PolicyOutcome::Abstain, $decision->outcome);
    }
}
