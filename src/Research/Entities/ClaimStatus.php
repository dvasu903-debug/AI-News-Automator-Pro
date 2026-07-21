<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

/**
 * A Claim's verification state, derived from its evidence links and any
 * unresolved Contradiction records.
 */
enum ClaimStatus: string
{
    case Unverified  = 'unverified';  // extracted, not yet cross-checked
    case Supported   = 'supported';   // has corroborating evidence, no unresolved contradiction
    case Contradicted = 'contradicted'; // has an unresolved Contradiction record
}
