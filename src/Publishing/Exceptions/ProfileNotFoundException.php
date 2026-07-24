<?php
/**
 * Raised when an operation references a nonexistent profile id.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Exceptions;

use RuntimeException;

class ProfileNotFoundException extends RuntimeException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Publishing profile %d not found.', $id));
    }
}
