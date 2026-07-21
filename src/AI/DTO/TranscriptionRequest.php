<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

final class TranscriptionRequest
{
    public function __construct(
        public readonly string $audioBase64,
        public readonly string $mediaType,
        public readonly string $model,
        public readonly ?string $correlationId = null,
    ) {
    }
}
