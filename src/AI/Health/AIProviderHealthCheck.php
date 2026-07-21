<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Health;

use AINewsAutomator\AI\Contracts\ProviderRegistryInterface;
use AINewsAutomator\AI\DTO\ProviderHealthStatus;
use AINewsAutomator\Security\Health\HealthCheckResult;
use AINewsAutomator\Security\Health\HealthStatus;

/**
 * Reuses Security's HealthCheckResult shape — the third module to do so
 * (Storage was the second) — so every module's diagnostics page renders
 * identically regardless of which module produced the result.
 */
final class AIProviderHealthCheck
{
    private const DOCS_BASE = 'https://example.com/ai-news-automator/docs/ai#';

    public function __construct(private readonly ProviderRegistryInterface $registry)
    {
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        $providers = $this->registry->all();

        if ($providers === []) {
            return [new HealthCheckResult(
                'AI Providers',
                HealthStatus::Warning,
                'No AI providers are registered.',
                'Configure at least one provider (API key + enable) in AI settings.',
                false,
                self::DOCS_BASE . 'no-providers'
            )];
        }

        return array_map(function ($provider): HealthCheckResult {
            $health = $provider->healthCheck();

            $status = match ($health->status) {
                ProviderHealthStatus::Healthy     => HealthStatus::Ok,
                ProviderHealthStatus::Degraded    => HealthStatus::Warning,
                ProviderHealthStatus::Unavailable => HealthStatus::Critical,
                ProviderHealthStatus::Unknown     => HealthStatus::Warning,
            };

            $message = $health->detail ?? sprintf('Provider "%s" reporting %s.', $provider->displayName(), $health->status->value);

            return new HealthCheckResult(
                sprintf('Provider: %s', $provider->displayName()),
                $status,
                $message,
                $status !== HealthStatus::Ok ? 'Check recent errors in the AI request log and verify the API key is valid.' : '',
                false,
                self::DOCS_BASE . 'provider-health'
            );
        }, $providers);
    }
}
