<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Exceptions;

/**
 * Thrown on transaction begin/commit/rollback failure, or when a nested
 * transaction/savepoint operation is used incorrectly.
 */
final class TransactionException extends StorageException
{
}
