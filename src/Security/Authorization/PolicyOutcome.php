<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

/**
 * The three outcomes a policy can return. Abstain means "this policy has
 * no opinion" — distinct from Deny, so a policy that doesn't apply to a
 * given actor doesn't accidentally block them.
 */
enum PolicyOutcome: string
{
    case Allow   = 'allow';
    case Deny    = 'deny';
    case Abstain = 'abstain';
}
