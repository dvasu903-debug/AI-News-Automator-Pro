<?php
/**
 * Raised when a profile slug collides with an existing profile.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Exceptions;

class DuplicateSlugException extends ProfileValidationException
{
    private string $slug;

    public static function forSlug(string $slug): self
    {
        $instance       = new self([sprintf('A profile with slug "%s" already exists.', $slug)]);
        $instance->slug = $slug;

        return $instance;
    }

    public function slug(): string
    {
        return $this->slug;
    }
}
