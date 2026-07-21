<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Restores a backup payload previously produced by BackupInterface,
 * dispatching each section to the matching ImporterInterface.
 */
interface RestorableInterface
{
    /**
     * @param array<string, mixed> $backup
     * @return array<string, int> key => records imported
     */
    public function restore(array $backup): array;
}
