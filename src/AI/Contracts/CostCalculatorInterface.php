<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\Usage;

/**
 * Separated from provider adapters entirely — a provider never computes
 * its own cost. Pricing updates mean updating ModelCatalogInterface's
 * data (which this depends on), never touching a provider class.
 */
interface CostCalculatorInterface
{
    public function calculate(string $providerId, string $model, Usage $usage): int;
}
