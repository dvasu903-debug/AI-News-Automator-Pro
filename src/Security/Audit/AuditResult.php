<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Audit;

/**
 * Whether the audited action succeeded or failed.
 */
enum AuditResult: string
{
    case Success = 'success';
    case Failure = 'failure';
}
