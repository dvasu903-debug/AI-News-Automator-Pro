<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * One incremental piece of a streamed chat response, yielded by
 * StreamingProviderInterface::streamChat(). `isFinal` marks the terminal
 * chunk, which carries the accumulated Usage (most providers only report
 * token counts once streaming completes).
 */
final class ChatChunk
{
    public function __construct(
        public readonly string $delta,
        public readonly bool $isFinal = false,
        public readonly ?Usage $usage = null,
        public readonly ?StopReason $stopReason = null,
    ) {
    }
}
