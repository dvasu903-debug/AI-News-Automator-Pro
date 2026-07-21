<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Exceptions;

/**
 * Thrown when Container::get() is called with an identifier that was
 * never bound via bind(), singleton(), or instance().
 */
class NotFoundException extends ContainerException
{
    public static function forIdentifier(string $id): self
    {
        return new self(sprintf(
            'No binding registered for "%s". Did you forget to register it in a ServiceProvider::register() method?',
            $id
        ));
    }
}
