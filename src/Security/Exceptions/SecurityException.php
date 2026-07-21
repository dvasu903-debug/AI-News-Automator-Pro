<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Exceptions;

/**
 * Base for every Security-module exception, so callers can catch the whole
 * family with one type when they only need "some security check failed".
 */
class SecurityException extends \RuntimeException
{
}
