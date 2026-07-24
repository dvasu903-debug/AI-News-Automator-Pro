<?php
/**
 * Raised when publishing configuration is missing or structurally
 * unusable at runtime — e.g. no default profile exists when a workflow
 * requires one. Fail fast; never silently choose another profile.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Exceptions;

use RuntimeException;

class PublishingConfigurationException extends RuntimeException
{
}
