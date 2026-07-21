<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * Capability + pricing truth for ONE specific model, the authoritative
 * layer capability resolution must consult (provider + selected model,
 * never provider alone — see the approved architecture decision). A
 * provider that generally supports vision may still have a specific
 * model that doesn't (e.g. a text-only "mini" tier).
 */
final class ModelCapabilities
{
    public function __construct(
        public readonly string $model,
        public readonly bool $vision = false,
        public readonly bool $toolCalling = false,
        public readonly bool $structuredOutput = false,
        public readonly int $contextWindow = 0,
        public readonly int $maxOutputTokens = 0,
        public readonly int $inputCostCentsPer1kTokens = 0,
        public readonly int $outputCostCentsPer1kTokens = 0,
    ) {
    }
}
