<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Emitted when a stored secret is decrypted for use. Never carries the
 * secret value — only its key and the accessor — so the event stream
 * itself is not a credential-leak vector.
 */
final class SecretAccessedEvent extends SecurityEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly string $secretKey,
        public readonly int $userId,
    ) {
        parent::__construct($metadata);
    }
}
