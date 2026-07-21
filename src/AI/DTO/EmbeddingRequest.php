<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

final class EmbeddingRequest
{
    /**
     * @param list<string> $inputs
     */
    public function __construct(
        public readonly array $inputs,
        public readonly string $model,
        public readonly ?string $correlationId = null,
    ) {
    }

    public function cacheKey(): string
    {
        return 'embed:' . md5($this->model . '|' . implode('|', $this->inputs));
    }
}
