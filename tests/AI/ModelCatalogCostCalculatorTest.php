<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\Cost\ModelCatalogCostCalculator;
use AINewsAutomator\AI\DTO\ModelCapabilities;
use AINewsAutomator\AI\DTO\Usage;
use AINewsAutomator\AI\Contracts\ModelCatalogInterface;
use PHPUnit\Framework\TestCase;

final class ModelCatalogCostCalculatorTest extends TestCase
{
    private function catalogWith(?ModelCapabilities $capabilities): ModelCatalogInterface
    {
        return new class ($capabilities) implements ModelCatalogInterface {
            public function __construct(private readonly ?ModelCapabilities $capabilities)
            {
            }

            public function capabilitiesFor(string $providerId, string $model): ?ModelCapabilities
            {
                return $this->capabilities;
            }

            public function modelsFor(string $providerId): array
            {
                return [];
            }

            public function refresh(string $providerId): bool
            {
                return false;
            }
        };
    }

    public function test_calculates_cost_from_catalog_pricing(): void
    {
        $capabilities = new ModelCapabilities(
            model: 'test-model',
            inputCostCentsPer1kTokens: 10,
            outputCostCentsPer1kTokens: 30,
        );

        $calculator = new ModelCatalogCostCalculator($this->catalogWith($capabilities));
        $usage = new Usage(promptTokens: 2000, completionTokens: 1000);

        // 2000 tokens @ 10 cents/1k = 20 cents; 1000 tokens @ 30 cents/1k = 30 cents; total 50.
        $this->assertSame(50, $calculator->calculate('provider', 'test-model', $usage));
    }

    public function test_unknown_model_returns_zero_rather_than_erroring(): void
    {
        $calculator = new ModelCatalogCostCalculator($this->catalogWith(null));
        $usage = new Usage(promptTokens: 1000, completionTokens: 1000);

        $this->assertSame(0, $calculator->calculate('provider', 'unknown-model', $usage));
    }

    public function test_pricing_change_requires_no_provider_or_calculator_code_change(): void
    {
        // Demonstrates the separation: swapping the catalog's pricing data
        // changes cost output without touching ModelCatalogCostCalculator.
        $cheap = new ModelCapabilities(model: 'm', inputCostCentsPer1kTokens: 1, outputCostCentsPer1kTokens: 1);
        $expensive = new ModelCapabilities(model: 'm', inputCostCentsPer1kTokens: 100, outputCostCentsPer1kTokens: 100);
        $usage = new Usage(1000, 1000);

        $cheapResult = (new ModelCatalogCostCalculator($this->catalogWith($cheap)))->calculate('p', 'm', $usage);
        $expensiveResult = (new ModelCatalogCostCalculator($this->catalogWith($expensive)))->calculate('p', 'm', $usage);

        $this->assertSame(2, $cheapResult);
        $this->assertSame(200, $expensiveResult);
    }
}
