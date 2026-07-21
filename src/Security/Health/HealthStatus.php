<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Health;

/**
 * The outcome status of a single security health check.
 */
enum HealthStatus: string
{
    case Ok       = 'ok';
    case Warning  = 'warning';
    case Critical = 'critical';
}
