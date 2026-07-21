<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

final class SpeechResponse
{
    public function __construct(
        public readonly string $audioBase64,
        public readonly string $mediaType,
        public readonly string $providerId,
        public readonly string $model,
    ) {
    }
}
