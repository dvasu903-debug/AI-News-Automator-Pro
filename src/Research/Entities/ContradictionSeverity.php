<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

/**
 * How serious a detected contradiction is. Publishing (Module 8, future)
 * will block on Critical/High by default — see the approved compliance
 * scope ("contradiction blocking").
 */
enum ContradictionSeverity: string
{
    case Low      = 'low';      // minor factual variance (e.g. differing figures within plausible rounding)
    case Medium   = 'medium';   // a meaningful factual disagreement
    case High     = 'high';     // directly opposing claims about the same fact
    case Critical = 'critical'; // opposing claims from otherwise high-credibility sources

    public function blocksPublishing(): bool
    {
        return match ($this) {
            self::High, self::Critical => true,
            default => false,
        };
    }
}
