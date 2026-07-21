<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class AIProviderUnavailableEvent extends AIEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly string $providerId,
        public readonly int $attempts,
        public readonly string $detail,
    ) {
        parent::__construct($metadata);
    }
}
