<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\ExportImport;

use AINewsAutomator\Storage\Contracts\ImporterInterface;
use AINewsAutomator\Storage\Contracts\WorkflowRepositoryInterface;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\WorkflowRecord;
use AINewsAutomator\Storage\Exceptions\ValidationException;

final class WorkflowsImporter implements ImporterInterface
{
    public function __construct(private readonly WorkflowRepositoryInterface $workflows)
    {
    }

    public function key(): string
    {
        return 'workflows';
    }

    public function import(array $payload): int
    {
        if (!isset($payload['workflows']) || !is_array($payload['workflows'])) {
            throw new ValidationException(['workflows' => 'Missing or malformed "workflows" array.'], 'Invalid workflows import payload.');
        }

        $count = 0;

        foreach ($payload['workflows'] as $row) {
            $record = new WorkflowRecord(
                id: null,
                name: (string) ($row['name'] ?? ''),
                vertical: (string) ($row['vertical'] ?? 'news'),
                definition: (array) ($row['definition'] ?? []),
                enabled: (bool) ($row['enabled'] ?? true),
                createdAt: EntityDates::now(),
                updatedAt: EntityDates::now(),
            );

            $this->workflows->save($record);
            $count++;
        }

        return $count;
    }
}
