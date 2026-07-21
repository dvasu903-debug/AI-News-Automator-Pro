<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\ExportImport;

use AINewsAutomator\Storage\Contracts\BackupInterface;
use AINewsAutomator\Storage\Contracts\ExporterInterface;
use AINewsAutomator\Storage\Contracts\ImporterInterface;
use AINewsAutomator\Storage\Contracts\RestorableInterface;

/**
 * Orchestrates every registered ExporterInterface/ImporterInterface into
 * one backup/restore payload. Exporters/importers are collected via the
 * container's tagging mechanism (`storage.exporters` / `storage.importers`,
 * see StorageServiceProvider) — the same "tagged collection" pattern used
 * for Security's policy engine, so a future module can register its own
 * exporter without this class changing.
 */
final class BackupManager implements BackupInterface, RestorableInterface
{
    /**
     * @param list<ExporterInterface> $exporters
     * @param list<ImporterInterface> $importers
     */
    public function __construct(
        private readonly array $exporters,
        private readonly array $importers,
    ) {
    }

    public function createBackup(): array
    {
        $backup = [
            'generated_at' => gmdate('c'),
            'sections'     => [],
        ];

        foreach ($this->exporters as $exporter) {
            $backup['sections'][$exporter->key()] = $exporter->export();
        }

        return $backup;
    }

    public function restore(array $backup): array
    {
        $sections = $backup['sections'] ?? [];
        $results = [];

        foreach ($this->importers as $importer) {
            $key = $importer->key();

            if (isset($sections[$key]) && is_array($sections[$key])) {
                $results[$key] = $importer->import($sections[$key]);
            }
        }

        return $results;
    }
}
