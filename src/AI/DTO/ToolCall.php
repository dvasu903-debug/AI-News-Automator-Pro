<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * A model's request to invoke a tool, extracted from a ChatResponse.
 */
final class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $toolName,
        public readonly array $arguments,
    ) {
    }
}
