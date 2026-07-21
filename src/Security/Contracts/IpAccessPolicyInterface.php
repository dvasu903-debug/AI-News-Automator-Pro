<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * FUTURE EXTENSION POINT (not implemented in Module 2). IP allow/block
 * decisions with CIDR ranges, expiry, and geo rules. The PolicyEngine
 * already supports IP-based policies today; this is the seam for a fuller
 * dedicated manager later.
 */
interface IpAccessPolicyInterface
{
    public function isAllowed(string $ip): bool;

    public function isBlocked(string $ip): bool;
}
