<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * The caller's result for a previously-issued ToolCall, fed back into the
 * next ChatRequest as part of the conversation history.
 */
final class ToolResult
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $content,
        public readonly bool $isError = false,
    ) {
    }
}
