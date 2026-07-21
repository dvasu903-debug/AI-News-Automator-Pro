<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Health;

/**
 * The result of one security health check. Richer than a pass/fail boolean:
 * carries a status, a severity, an actionable recommendation, whether an
 * automated fix is available, and a documentation link. This shape is built
 * to be consumed directly by the Monitoring module's health dashboard later,
 * as well as rendered on the Security diagnostics page now.
 */
final class HealthCheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly HealthStatus $status,
        public readonly string $message,
        public readonly string $recommendation = '',
        public readonly bool $autoFixAvailable = false,
        public readonly string $docsUrl = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'               => $this->name,
            'status'             => $this->status->value,
            'message'            => $this->message,
            'recommendation'     => $this->recommendation,
            'auto_fix_available' => $this->autoFixAvailable,
            'docs_url'           => $this->docsUrl,
        ];
    }
}
