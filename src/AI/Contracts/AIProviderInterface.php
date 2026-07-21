<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\ProviderCapabilities;
use AINewsAutomator\AI\DTO\ProviderHealth;

/**
 * The base contract every AI provider adapter implements. This alone
 * grants no capability — a provider additionally implements whichever of
 * ChatProviderInterface, ImageProviderInterface, EmbeddingProviderInterface,
 * SpeechProviderInterface, StreamingProviderInterface it genuinely
 * supports (Interface Segregation — see the approved architecture
 * comparison). capabilities() is the coarse, provider-level declarative
 * summary; authoritative per-request capability truth comes from
 * ModelCatalogInterface (provider + selected model, never provider alone).
 */
interface AIProviderInterface
{
    /** Stable identifier, e.g. "claude", "openai", "openrouter". */
    public function id(): string;

    public function displayName(): string;

    public function capabilities(): ProviderCapabilities;

    public function healthCheck(): ProviderHealth;
}
