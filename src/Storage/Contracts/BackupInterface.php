<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Orchestrates multiple ExporterInterface implementations into a single
 * backup payload.
 */
interface BackupInterface
{
    /**
     * @return array<string, mixed> Keyed by each exporter's key().
     */
    public function createBackup(): array;
}
