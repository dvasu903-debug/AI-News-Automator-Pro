<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Security;

use AINewsAutomator\Security\Authorization\AuthorizationContext;
use AINewsAutomator\Security\Authorization\PolicyDecision;
use AINewsAutomator\Security\Authorization\PolicyEngine;
use AINewsAutomator\Security\Contracts\PolicyInterface;
use PHPUnit\Framework\TestCase;

/**
 * Permission regression matrix for the PolicyEngine resolution rules:
 *   any Deny -> denied; else any Allow -> allowed; else -> default deny.
 */
final class PolicyEngineTest extends TestCase
{
    private function context(): AuthorizationContext
    {
        return new AuthorizationContext(userId: 1, ip: '203.0.113.5');
    }

    public function test_no_policies_defaults_to_deny(): void
    {
        $engine = new PolicyEngine([]);
        $this->assertFalse($engine->evaluate('anything', $this->context())->allowed);
    }

    public function test_single_allow_grants(): void
    {
        $engine = new PolicyEngine([$this->policy('a', PolicyDecision::allow('p'))]);
        $this->assertTrue($engine->evaluate('a', $this->context())->allowed);
    }

    public function test_single_deny_refuses(): void
    {
        $engine = new PolicyEngine([$this->policy('a', PolicyDecision::deny('p'))]);
        $this->assertFalse($engine->evaluate('a', $this->context())->allowed);
    }

    public function test_deny_overrides_allow_regardless_of_order(): void
    {
        $allow = $this->policy('a', PolicyDecision::allow('allower'));
        $deny  = $this->policy('a', PolicyDecision::deny('denier'));

        $this->assertFalse((new PolicyEngine([$allow, $deny]))->evaluate('a', $this->context())->allowed);
        $this->assertFalse((new PolicyEngine([$deny, $allow]))->evaluate('a', $this->context())->allowed);
    }

    public function test_all_abstain_defaults_to_deny(): void
    {
        $engine = new PolicyEngine([
            $this->policy('a', PolicyDecision::abstain('p1')),
            $this->policy('a', PolicyDecision::abstain('p2')),
        ]);

        $this->assertFalse($engine->evaluate('a', $this->context())->allowed);
    }

    public function test_abstain_plus_allow_grants(): void
    {
        $engine = new PolicyEngine([
            $this->policy('a', PolicyDecision::abstain('p1')),
            $this->policy('a', PolicyDecision::allow('p2')),
        ]);

        $this->assertTrue($engine->evaluate('a', $this->context())->allowed);
    }

    public function test_policy_not_handling_ability_is_ignored(): void
    {
        // Policy only handles "other", so for "a" it doesn't participate;
        // with no participating allow, result is default-deny.
        $engine = new PolicyEngine([$this->policy('other', PolicyDecision::allow('p'))]);
        $this->assertFalse($engine->evaluate('a', $this->context())->allowed);
    }

    public function test_wildcard_policy_participates_in_every_ability(): void
    {
        $engine = new PolicyEngine([$this->policy('*', PolicyDecision::deny('global-block'))]);
        $this->assertFalse($engine->evaluate('any.ability', $this->context())->allowed);
    }

    public function test_result_exposes_reason(): void
    {
        $engine = new PolicyEngine([$this->policy('a', PolicyDecision::deny('p', 'blocked because reasons'))]);
        $result = $engine->evaluate('a', $this->context());

        $this->assertSame('blocked because reasons', $result->reasonSummary());
    }

    private function policy(string $ability, PolicyDecision $decision): PolicyInterface
    {
        return new class ($ability, $decision) implements PolicyInterface {
            public function __construct(
                private readonly string $ability,
                private readonly PolicyDecision $decision
            ) {
            }

            public function handledAbilities(): array
            {
                return [$this->ability];
            }

            public function decide(string $ability, AuthorizationContext $context): PolicyDecision
            {
                return $this->decision;
            }
        };
    }
}
