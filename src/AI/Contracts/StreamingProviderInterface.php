<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\ChatChunk;
use AINewsAutomator\AI\DTO\ChatRequest;

/**
 * Streaming is a real, first-class capability but deliberately NOT the
 * default execution path — the engine's current consumers are background
 * queue jobs with no observer for incremental tokens. AIManager exposes
 * this via a distinct streamChat() method; chat() never streams
 * internally. See module README for the full rationale.
 */
interface StreamingProviderInterface extends AIProviderInterface
{
    /**
     * @return iterable<ChatChunk>
     */
    public function streamChat(ChatRequest $request): iterable;
}
