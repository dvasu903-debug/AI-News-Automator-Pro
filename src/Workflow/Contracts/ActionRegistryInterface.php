<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

/**
 * Mirrors AI\Contracts\ProviderRegistryInterface and
 * Sources\Contracts\SourceConnectorRegistryInterface exactly — the third
 * instance of the same discovery-separated-from-orchestration shape.
 * Workflow's own service provider registers only its generic actions
 * (Wait, ApprovalGate, Notification, QueueJob); every other module
 * registers its own action(s) from its own service provider's boot(),
 * exactly the way Sources' connectors and AI's providers register
 * themselves without Workflow having compile-time knowledge of them.
 */
interface ActionRegistryInterface
{
    public function register(ActionInterface $action): void;

    public function forType(string $type): ?ActionInterface;

    /**
     * @return list<ActionInterface>
     */
    public function all(): array;
}
