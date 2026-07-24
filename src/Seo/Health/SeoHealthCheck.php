<?php
/**
 * SEO module's health check, mirroring PublishingHealthCheck/
 * WorkflowHealthCheck's plain run(): array shape (no formal shared
 * interface exists yet — see those classes' docblocks).
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo\Health;

use AINewsAutomator\Seo\Contracts\SeoProviderInterface;
use AINewsAutomator\Security\Health\HealthCheckResult;
use AINewsAutomator\Security\Health\HealthStatus;

final class SeoHealthCheck
{
    public function __construct(private readonly SeoProviderInterface $provider)
    {
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        return [
            new HealthCheckResult(
                'SEO provider',
                HealthStatus::Ok,
                sprintf('SeoProviderInterface resolves to %s.', get_class($this->provider))
            ),
        ];
    }
}
