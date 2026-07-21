<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

final class EmbeddingResponse
{
    /**
     * @param list<list<float>> $vectors One vector per input, same order.
     */
    public function __construct(
        public readonly array $vectors,
        public readonly Usage $usage,
        public readonly string $providerId,
        public readonly string $model,
        public readonly bool $fromCache = false,
    ) {
    }
}
