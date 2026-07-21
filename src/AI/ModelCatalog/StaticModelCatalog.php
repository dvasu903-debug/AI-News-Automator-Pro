<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\ModelCatalog;

use AINewsAutomator\AI\Contracts\ModelCatalogInterface;
use AINewsAutomator\AI\DTO\ModelCapabilities;
use AINewsAutomator\Core\Contracts\LoggerInterface;

/**
 * Default ModelCatalogInterface implementation: a hardcoded seed dataset.
 *
 * This is EXPLICITLY not meant to be the permanent source of truth — the
 * approved requirement is "avoid hard-coded permanent model lists." This
 * class exists so the engine has *something* to validate requests against
 * on day one; refresh() is a documented no-op here, the extension point a
 * future implementation (e.g. one that calls each provider's /models
 * endpoint) fills in without AIManager or any other consumer changing,
 * since everything depends on ModelCatalogInterface, never this class.
 *
 * Pricing figures are illustrative starting values, not a live price
 * feed — verify current pricing before relying on cost estimates for
 * billing-sensitive decisions.
 */
final class StaticModelCatalog implements ModelCatalogInterface
{
    /** @var array<string, array<string, ModelCapabilities>> */
    private array $catalog;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->catalog = $this->seedData();
    }

    public function capabilitiesFor(string $providerId, string $model): ?ModelCapabilities
    {
        return $this->catalog[$providerId][$model] ?? null;
    }

    public function modelsFor(string $providerId): array
    {
        return array_keys($this->catalog[$providerId] ?? []);
    }

    public function refresh(string $providerId): bool
    {
        $this->logger->info('ModelCatalog refresh requested for "{provider}" but StaticModelCatalog has no live source — no-op.', [
            'provider' => $providerId,
        ]);

        return false;
    }

    /**
     * @return array<string, array<string, ModelCapabilities>>
     */
    private function seedData(): array
    {
        return [
            'claude' => [
                'claude-sonnet-5' => new ModelCapabilities(
                    model: 'claude-sonnet-5',
                    vision: true,
                    toolCalling: true,
                    structuredOutput: true,
                    contextWindow: 1_000_000,
                    maxOutputTokens: 128_000,
                ),
                'claude-haiku-4-5' => new ModelCapabilities(
                    model: 'claude-haiku-4-5',
                    vision: true,
                    toolCalling: true,
                    structuredOutput: true,
                    contextWindow: 200_000,
                    maxOutputTokens: 64_000,
                ),
            ],
            'openai' => [
                'gpt-5.5' => new ModelCapabilities(
                    model: 'gpt-5.5',
                    vision: true,
                    toolCalling: true,
                    structuredOutput: true,
                    contextWindow: 1_000_000,
                    maxOutputTokens: 128_000,
                ),
                'gpt-image-2' => new ModelCapabilities(
                    model: 'gpt-image-2',
                ),
                'text-embedding-3-small' => new ModelCapabilities(
                    model: 'text-embedding-3-small',
                    contextWindow: 8191,
                ),
            ],
            'gemini' => [
                'gemini-3.1-pro-preview' => new ModelCapabilities(
                    model: 'gemini-3.1-pro-preview',
                    vision: true,
                    toolCalling: true,
                    structuredOutput: true,
                    contextWindow: 1_000_000,
                    maxOutputTokens: 65_536,
                ),
            ],
            'deepseek' => [
                'deepseek-chat' => new ModelCapabilities(
                    model: 'deepseek-chat',
                    toolCalling: true,
                    structuredOutput: true,
                    contextWindow: 128_000,
                    maxOutputTokens: 8_000,
                ),
            ],
            'grok' => [
                'grok-4.5' => new ModelCapabilities(
                    model: 'grok-4.5',
                    vision: true,
                    toolCalling: true,
                    structuredOutput: true,
                    contextWindow: 256_000,
                    maxOutputTokens: 64_000,
                ),
            ],
            'openrouter' => [
                // Deliberately sparse: OpenRouter's own capability is
                // "whatever model is currently routed" — its catalog entry
                // set is expected to grow via refresh()/admin config per
                // routed model, not be hardcoded exhaustively here.
            ],
            'ollama' => [
                // Local models are whatever the site operator has pulled;
                // no meaningful global default list exists. Admin config
                // (Settings page) is expected to register locally-available
                // models, not this static seed.
            ],
        ];
    }
}
