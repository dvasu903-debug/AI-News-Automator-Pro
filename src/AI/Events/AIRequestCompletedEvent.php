<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class AIRequestCompletedEvent extends AIEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly string $providerId,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $costCents,
        public readonly float $latencyMs,
        public readonly bool $fromCache,
    ) {
        parent::__construct($metadata);
    }
}
