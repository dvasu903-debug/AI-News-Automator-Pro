<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * A provider-agnostic chat response, translated from whichever vendor
 * shape the adapter received. `raw` preserves the untranslated provider
 * response for debugging without forcing every caller to know the vendor
 * shape.
 */
final class ChatResponse
{
    /**
     * @param list<ToolCall> $toolCalls
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls,
        public readonly Usage $usage,
        public readonly StopReason $stopReason,
        public readonly string $providerId,
        public readonly string $model,
        public readonly array $raw = [],
        public readonly bool $fromCache = false,
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function withFromCache(bool $fromCache): self
    {
        return new self(
            $this->content,
            $this->toolCalls,
            $this->usage,
            $this->stopReason,
            $this->providerId,
            $this->model,
            $this->raw,
            $fromCache
        );
    }
}
