<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\ModelCapabilities;

/**
 * Per-model capability and pricing truth — the authoritative layer for
 * capability resolution (provider + selected model, never provider
 * alone). "Avoid hard-coded permanent model lists": refresh() is the
 * extension point for a future implementation that syncs from a
 * provider's own /models endpoint; the default StaticModelCatalog's
 * refresh() is a documented no-op, not a promise of live data.
 */
interface ModelCatalogInterface
{
    public function capabilitiesFor(string $providerId, string $model): ?ModelCapabilities;

    /**
     * @return list<string> Every known model id for a provider.
     */
    public function modelsFor(string $providerId): array;

    /**
     * Attempts to refresh catalog data from an external source. Returns
     * false if this implementation has no live source (e.g. the static
     * default) — callers should not treat false as an error.
     */
    public function refresh(string $providerId): bool;
}
