<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * A provider's last-known health, consulted by FailoverChain so failover
 * considers actual provider health rather than only capability +
 * priority ("do not fail over blindly").
 */
final class ProviderHealth
{
    public function __construct(
        public readonly string $providerId,
        public readonly ProviderHealthStatus $status,
        public readonly ?float $lastLatencyMs = null,
        public readonly ?\DateTimeImmutable $checkedAt = null,
        public readonly ?string $detail = null,
    ) {
    }

    public function isEligibleForFailover(): bool
    {
        return $this->status !== ProviderHealthStatus::Unavailable;
    }
}
