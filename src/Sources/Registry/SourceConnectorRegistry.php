<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Registry;

use AINewsAutomator\Sources\Contracts\SourceConnectorInterface;
use AINewsAutomator\Sources\Contracts\SourceConnectorRegistryInterface;

/**
 * The only way a connector is resolved — mirrors AI's ProviderRegistry
 * exactly. Nothing outside SourcesServiceProvider ever instantiates a
 * connector class directly.
 */
final class SourceConnectorRegistry implements SourceConnectorRegistryInterface
{
    /** @var array<string, SourceConnectorInterface> */
    private array $connectors = [];

    public function register(SourceConnectorInterface $connector): void
    {
        $this->connectors[$connector->type()] = $connector;
    }

    public function forType(string $type): ?SourceConnectorInterface
    {
        return $this->connectors[$type] ?? null;
    }

    public function all(): array
    {
        return array_values($this->connectors);
    }
}
