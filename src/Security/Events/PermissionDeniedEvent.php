<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Emitted when an ability check is denied. Consumed by ThreatDetector
 * (repeated denials => possible privilege-escalation probing) and by
 * Monitoring later.
 */
final class PermissionDeniedEvent extends SecurityEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly string $ability,
        public readonly int $userId,
        public readonly string $ip,
        public readonly string $reason,
    ) {
        parent::__construct($metadata);
    }
}
