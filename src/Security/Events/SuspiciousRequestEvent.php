<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Emitted on a request that fails integrity validation in a way that
 * suggests malice rather than a benign mistake (e.g. a blocked SSRF
 * target, a replayed/forged nonce). Feeds ThreatDetector.
 */
final class SuspiciousRequestEvent extends SecurityEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly string $kind,
        public readonly string $detail,
        public readonly string $ip,
    ) {
        parent::__construct($metadata);
    }
}
