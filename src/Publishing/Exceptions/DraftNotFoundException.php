<?php
/**
 * Raised when an operation references a post id that isn't a known draft.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Exceptions;

use RuntimeException;

class DraftNotFoundException extends RuntimeException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Draft post %d not found.', $id));
    }
}
