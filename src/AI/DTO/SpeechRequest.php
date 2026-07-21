<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

final class SpeechRequest
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly string $voice = 'default',
        public readonly ?string $correlationId = null,
    ) {
    }
}
