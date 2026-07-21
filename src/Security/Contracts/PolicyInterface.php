<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

use AINewsAutomator\Security\Authorization\AuthorizationContext;
use AINewsAutomator\Security\Authorization\PolicyDecision;

/**
 * A security policy. Future modules implement this and register their
 * instances under the container tag "security.policies" to participate
 * in authorization decisions without modifying the Security module.
 *
 * Each policy declares which abilities it handles (e.g. "content.approve")
 * and, when consulted, returns an Allow / Deny / Abstain decision. The
 * PolicyEngine aggregates all handling policies' decisions; explicit Deny
 * always wins, so a single policy can veto regardless of others.
 */
interface PolicyInterface
{
    /**
     * @return list<string> Abilities this policy has an opinion on. Support
     *                      "*" to handle every ability (e.g. an IP blocklist
     *                      that can deny anything).
     */
    public function handledAbilities(): array;

    public function decide(string $ability, AuthorizationContext $context): PolicyDecision;
}
