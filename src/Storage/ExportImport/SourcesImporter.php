<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\ExportImport;

use AINewsAutomator\Storage\Contracts\ImporterInterface;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\SourceRecord;
use AINewsAutomator\Storage\Exceptions\ValidationException;

final class SourcesImporter implements ImporterInterface
{
    public function __construct(private readonly SourceRepositoryInterface $sources)
    {
    }

    public function key(): string
    {
        return 'sources';
    }

    public function import(array $payload): int
    {
        if (!isset($payload['sources']) || !is_array($payload['sources'])) {
            throw new ValidationException(['sources' => 'Missing or malformed "sources" array.'], 'Invalid sources import payload.');
        }

        $count = 0;

        foreach ($payload['sources'] as $row) {
            $record = new SourceRecord(
                id: null,
                name: (string) ($row['name'] ?? ''),
                type: (string) ($row['type'] ?? ''),
                config: (array) ($row['config'] ?? []),
                enabled: (bool) ($row['enabled'] ?? true),
                lastFetchedAt: null,
                lastError: null,
                createdAt: EntityDates::now(),
                updatedAt: EntityDates::now(),
            );

            $this->sources->save($record);
            $count++;
        }

        return $count;
    }
}
