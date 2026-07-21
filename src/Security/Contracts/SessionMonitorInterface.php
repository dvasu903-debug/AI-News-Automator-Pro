<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * FUTURE EXTENSION POINT (not implemented in Module 2). Active-session
 * inspection (concurrent sessions, anomalous locations).
 */
interface SessionMonitorInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function activeSessions(int $userId): array;

    public function terminate(int $userId, string $sessionToken): void;
}
