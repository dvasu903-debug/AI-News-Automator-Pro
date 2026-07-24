<?php
/**
 * Raised when a profile name collides with an existing profile.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Exceptions;

class DuplicateNameException extends ProfileValidationException
{
    private string $name;

    public static function forName(string $name): self
    {
        $instance       = new self([sprintf('A profile with name "%s" already exists.', $name)]);
        $instance->name = $name;

        return $instance;
    }

    public function name(): string
    {
        return $this->name;
    }
}
