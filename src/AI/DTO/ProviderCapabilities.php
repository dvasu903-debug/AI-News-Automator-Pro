<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * Declarative, provider-level capability summary — "does this provider
 * EVER support X across any of its models." Used for coarse routing and
 * failover eligibility (instanceof-equivalent, but iterable/displayable
 * without reflection). Fine-grained, per-model capability truth comes
 * from ModelCapabilities via ModelCatalogInterface — this object is
 * deliberately the coarse layer, not the authoritative one (see module
 * README, capability resolution).
 */
final class ProviderCapabilities
{
    public function __construct(
        public readonly bool $chat = false,
        public readonly bool $streaming = false,
        public readonly bool $vision = false,
        public readonly bool $toolCalling = false,
        public readonly bool $structuredOutput = false,
        public readonly bool $imageGeneration = false,
        public readonly bool $embeddings = false,
        public readonly bool $speech = false,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'chat'              => $this->chat,
            'streaming'         => $this->streaming,
            'vision'            => $this->vision,
            'tool_calling'      => $this->toolCalling,
            'structured_output' => $this->structuredOutput,
            'image_generation'  => $this->imageGeneration,
            'embeddings'        => $this->embeddings,
            'speech'            => $this->speech,
        ];
    }
}
