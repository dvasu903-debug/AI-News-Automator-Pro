<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class AIFailoverTriggeredEvent extends AIEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly string $fromProviderId,
        public readonly string $toProviderId,
        public readonly string $capability,
    ) {
        parent::__construct($metadata);
    }
}
