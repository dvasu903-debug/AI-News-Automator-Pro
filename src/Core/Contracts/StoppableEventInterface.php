<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * Implemented by events that support halting further listener execution
 * — e.g. a validation event where one listener rejecting the payload
 * should prevent later listeners from acting on it.
 */
interface StoppableEventInterface
{
    public function isPropagationStopped(): bool;
}
