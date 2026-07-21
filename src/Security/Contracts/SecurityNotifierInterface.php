<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * FUTURE EXTENSION POINT (not implemented in Module 2). Delivery of security
 * notifications (email, Slack, webhook) on notable events — consumed by the
 * Notifications/Social modules later.
 */
interface SecurityNotifierInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function notify(string $severity, string $message, array $context = []): void;
}
