<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class AIRequestFailedEvent extends AIEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly string $providerId,
        public readonly string $model,
        public readonly string $errorType,
        public readonly string $errorMessage,
    ) {
        parent::__construct($metadata);
    }
}
