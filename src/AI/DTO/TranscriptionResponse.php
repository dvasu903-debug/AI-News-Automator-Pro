<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

final class TranscriptionResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $providerId,
        public readonly string $model,
    ) {
    }
}
