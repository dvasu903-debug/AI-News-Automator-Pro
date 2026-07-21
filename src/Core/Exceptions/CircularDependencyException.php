<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Exceptions;

/**
 * Thrown when the container detects a cycle while autowiring — e.g. A's
 * constructor needs B, and B's constructor needs A. Without detection
 * this manifests as an opaque infinite recursion / memory exhaustion;
 * with it, the developer gets the exact resolution chain that looped.
 */
final class CircularDependencyException extends ContainerException
{
    /**
     * @param list<string> $chain The resolution stack, in order, ending with the repeat.
     */
    public static function forChain(array $chain, string $repeated): self
    {
        return new self(sprintf(
            'Circular dependency detected while resolving "%s". Resolution chain: %s -> %s.',
            $repeated,
            implode(' -> ', $chain),
            $repeated
        ));
    }
}
