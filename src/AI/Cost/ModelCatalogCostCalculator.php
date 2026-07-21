<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Cost;

use AINewsAutomator\AI\Contracts\CostCalculatorInterface;
use AINewsAutomator\AI\Contracts\ModelCatalogInterface;
use AINewsAutomator\AI\DTO\Usage;

/**
 * Computes cost from ModelCatalogInterface's pricing data — never from a
 * provider adapter. A pricing update means updating the catalog (or
 * admin-configured overrides), never touching a provider class or this
 * calculator.
 */
final class ModelCatalogCostCalculator implements CostCalculatorInterface
{
    public function __construct(private readonly ModelCatalogInterface $catalog)
    {
    }

    public function calculate(string $providerId, string $model, Usage $usage): int
    {
        $capabilities = $this->catalog->capabilitiesFor($providerId, $model);

        if ($capabilities === null) {
            return 0;
        }

        $inputCost = ($usage->promptTokens / 1000) * $capabilities->inputCostCentsPer1kTokens;
        $outputCost = ($usage->completionTokens / 1000) * $capabilities->outputCostCentsPer1kTokens;

        return (int) round($inputCost + $outputCost);
    }
}
