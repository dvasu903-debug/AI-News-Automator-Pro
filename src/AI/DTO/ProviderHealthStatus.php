<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

enum ProviderHealthStatus: string
{
    case Healthy     = 'healthy';
    case Degraded    = 'degraded';
    case Unavailable = 'unavailable';
    case Unknown     = 'unknown';
}
