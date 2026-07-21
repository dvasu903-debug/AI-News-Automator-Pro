<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Exceptions;

/**
 * Thrown on any encryption/decryption failure: malformed payload, unknown
 * algorithm or key id, or authentication failure (tampering). Deliberately
 * does not leak cryptographic detail in its message.
 */
final class EncryptionException extends SecurityException
{
}
