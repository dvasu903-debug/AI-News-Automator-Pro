<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

final class ImageGenerationRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $model,
        public readonly string $size = '1024x1024',
        public readonly int $count = 1,
        public readonly ?string $correlationId = null,
    ) {
    }

    public function cacheKey(): string
    {
        return 'image:' . md5($this->prompt . '|' . $this->model . '|' . $this->size . '|' . $this->count);
    }
}
