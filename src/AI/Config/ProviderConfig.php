<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Config;

/**
 * Per-vendor configuration for OpenAiCompatibleProvider — this is what
 * lets OpenAI, OpenRouter, DeepSeek, Grok, and Ollama's OpenAI-compatible
 * endpoint share ONE class. Adding a new OpenAI-compatible vendor means
 * adding one of these, never a new class (approved architecture decision).
 */
final class ProviderConfig
{
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly string $baseUrl,
        public readonly string $chatEndpoint = '/chat/completions',
        public readonly string $authHeaderName = 'Authorization',
        public readonly string $authHeaderFormat = 'Bearer %s',
        public readonly bool $supportsVision = true,
        public readonly bool $supportsToolCalling = true,
        public readonly bool $supportsStructuredOutput = true,
        public readonly bool $supportsStreaming = true,
        public readonly ?string $secretKey = null,
        public readonly int $timeoutSeconds = 30,
    ) {
    }
}
