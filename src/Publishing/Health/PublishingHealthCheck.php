<?php
/**
 * Publishing's health check, mirroring WorkflowHealthCheck's plain
 * run(): array shape (no formal shared interface exists yet — see that
 * class's docblock).
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Health;

use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Security\Health\HealthCheckResult;
use AINewsAutomator\Security\Health\HealthStatus;

final class PublishingHealthCheck
{
    public function __construct(
        private readonly PublishingProfileRepositoryInterface $profiles,
    ) {
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        return [
            $this->checkEnabledProfilesExist(),
            $this->checkDefaultProfileConfigured(),
        ];
    }

    private function checkEnabledProfilesExist(): HealthCheckResult
    {
        $enabled = $this->profiles->findAll(true);

        if (count($enabled) === 0) {
            return new HealthCheckResult(
                'Publishing profiles',
                HealthStatus::Warning,
                'No enabled publishing profiles exist.',
                'Create at least one publishing profile before running the publish pipeline.'
            );
        }

        return new HealthCheckResult(
            'Publishing profiles',
            HealthStatus::Ok,
            sprintf('%d enabled publishing profile(s) configured.', count($enabled))
        );
    }

    private function checkDefaultProfileConfigured(): HealthCheckResult
    {
        if (null === $this->profiles->findDefault()) {
            return new HealthCheckResult(
                'Default publishing profile',
                HealthStatus::Warning,
                'No default publishing profile is configured.',
                'Mark a profile as default — any workflow-context call to requireDefault() will fail until one is set.'
            );
        }

        return new HealthCheckResult(
            'Default publishing profile',
            HealthStatus::Ok,
            'A default publishing profile is configured.'
        );
    }
}
