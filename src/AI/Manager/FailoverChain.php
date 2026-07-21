<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Manager;

use AINewsAutomator\AI\Contracts\AIProviderInterface;
use AINewsAutomator\AI\Contracts\FailoverPolicyInterface;
use AINewsAutomator\AI\Contracts\ProviderRegistryInterface;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;

/**
 * Selects a failover target considering, in order: capability eligibility
 * (structural — instanceof via the registry), administrator policy
 * (explicit exclusions from config), provider health (skips a provider
 * currently reporting Unavailable), and configured priority (an ordered
 * preference list) — never "blindly" picking the next registered provider.
 */
final class FailoverChain implements FailoverPolicyInterface
{
    public function __construct(
        private readonly ProviderRegistryInterface $registry,
        private readonly ConfigRepositoryInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function nextEligible(string $capabilityInterface, array $excludedProviderIds): ?AIProviderInterface
    {
        $candidates = $this->registry->allImplementing($capabilityInterface);

        $adminExcluded = $this->adminExcludedProviderIds();

        $eligible = array_values(array_filter($candidates, function (AIProviderInterface $provider) use ($excludedProviderIds, $adminExcluded): bool {
            if (in_array($provider->id(), $excludedProviderIds, true)) {
                return false;
            }

            if (in_array($provider->id(), $adminExcluded, true)) {
                return false;
            }

            if (!$provider->healthCheck()->isEligibleForFailover()) {
                return false;
            }

            return true;
        }));

        if ($eligible === []) {
            $this->logger->warning('No eligible failover target for capability {capability} (excluded: {excluded}).', [
                'capability' => $capabilityInterface,
                'excluded'   => implode(', ', $excludedProviderIds),
            ]);

            return null;
        }

        usort($eligible, function (AIProviderInterface $a, AIProviderInterface $b): int {
            return $this->priorityOf($a->id()) <=> $this->priorityOf($b->id());
        });

        return $eligible[0];
    }

    /**
     * @return list<string>
     */
    private function adminExcludedProviderIds(): array
    {
        $excluded = $this->config->get('ai.failover.excluded_providers', []);

        return is_array($excluded) ? array_values(array_map('strval', $excluded)) : [];
    }

    /**
     * Lower number = higher priority. Providers not listed in the
     * configured priority order sort after every explicitly-ordered one.
     */
    private function priorityOf(string $providerId): int
    {
        $order = $this->config->get('ai.failover.priority', []);
        $order = is_array($order) ? array_values(array_map('strval', $order)) : [];

        $index = array_search($providerId, $order, true);

        return $index === false ? PHP_INT_MAX : $index;
    }
}
