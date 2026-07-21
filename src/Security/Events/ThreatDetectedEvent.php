<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Emitted by ThreatDetector when accumulated signals cross a threshold
 * (e.g. N nonce failures from one IP within the window). This is the
 * event Monitoring will subscribe to for alerting.
 */
final class ThreatDetectedEvent extends SecurityEvent
{
    /**
     * @param array<string, mixed> $evidence
     */
    public function __construct(
        EventMetadata $metadata,
        public readonly string $threatType,
        public readonly string $subject,
        public readonly int $count,
        public readonly array $evidence = [],
    ) {
        parent::__construct($metadata);
    }
}
