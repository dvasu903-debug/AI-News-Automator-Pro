<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Registry;

use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\Contracts\ActionRegistryInterface;

/**
 * Mirrors AI\Registry\ProviderRegistry exactly — a plain array-keyed
 * register/get/all, no container magic, no discovery scanning. Every
 * module's actions (including Workflow's own generic ones) register
 * themselves here from their own service provider's boot(), the same
 * way Sources' connectors and AI's providers do.
 */
final class ActionRegistry implements ActionRegistryInterface
{
    /** @var array<string, ActionInterface> */
    private array $actions = [];

    public function register(ActionInterface $action): void
    {
        $this->actions[$action->type()] = $action;
    }

    public function forType(string $type): ?ActionInterface
    {
        return $this->actions[$type] ?? null;
    }

    public function all(): array
    {
        return array_values($this->actions);
    }
}
