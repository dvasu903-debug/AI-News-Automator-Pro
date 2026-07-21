<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

/**
 * Whether one piece of Evidence supports or contradicts a Claim, per
 * the ClaimEvidenceLink junction record.
 */
enum EvidenceRelationship: string
{
    case Supports    = 'supports';
    case Contradicts = 'contradicts';
}
