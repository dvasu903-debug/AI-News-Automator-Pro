<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Exceptions;

/**
 * Thrown when the container fails to resolve a binding — e.g. a closure
 * throws, or a class binding cannot be instantiated. Distinct from
 * NotFoundException, which means the identifier was never registered
 * at all.
 */
class ContainerException extends \RuntimeException
{
}
