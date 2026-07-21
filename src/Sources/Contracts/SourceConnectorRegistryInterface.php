<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

/**
 * The only way a connector is resolved — mirrors AI's
 * ProviderRegistryInterface exactly (discovery separated from
 * orchestration and translation, per the module's approved mental
 * model). Nothing outside SourcesServiceProvider ever instantiates a
 * connector class directly.
 */
interface SourceConnectorRegistryInterface
{
    public function register(SourceConnectorInterface $connector): void;

    public function forType(string $type): ?SourceConnectorInterface;

    /**
     * @return list<SourceConnectorInterface>
     */
    public function all(): array;
}
