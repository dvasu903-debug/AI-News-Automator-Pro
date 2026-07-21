<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

final class ImageGenerationResponse
{
    /**
     * @param list<string> $urls Image URLs or base64 data URIs, per vendor.
     */
    public function __construct(
        public readonly array $urls,
        public readonly string $providerId,
        public readonly string $model,
        public readonly ?int $costCents = null,
        public readonly bool $fromCache = false,
    ) {
    }
}
