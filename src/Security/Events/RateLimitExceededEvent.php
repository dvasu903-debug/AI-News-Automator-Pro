<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Emitted when a rate limit is exceeded. Repeated occurrences for one
 * actor/IP indicate abuse or a runaway integration.
 */
final class RateLimitExceededEvent extends SecurityEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly string $key,
        public readonly int $limit,
        public readonly int $window,
        public readonly string $ip,
    ) {
        parent::__construct($metadata);
    }
}
