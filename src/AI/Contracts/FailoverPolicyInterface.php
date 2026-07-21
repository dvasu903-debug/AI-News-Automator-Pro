<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

/**
 * Selects the next provider to try after the primary has exhausted its
 * retry budget. Must consider capability (structural eligibility),
 * provider health, configured priority, and administrator policy
 * (explicit exclusions) — never "blindly" picking the next registered
 * provider regardless of fitness.
 */
interface FailoverPolicyInterface
{
    /**
     * @param class-string $capabilityInterface The interface the request needs, e.g. ChatProviderInterface::class.
     * @param list<string> $excludedProviderIds Providers already tried in this call chain.
     */
    public function nextEligible(string $capabilityInterface, array $excludedProviderIds): ?AIProviderInterface;
}
