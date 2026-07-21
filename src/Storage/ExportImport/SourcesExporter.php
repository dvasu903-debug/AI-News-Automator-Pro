<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\ExportImport;

use AINewsAutomator\Storage\Contracts\ExporterInterface;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;

/**
 * Exports configured sources as JSON. Practical cap of 10,000 rows —
 * sources are low-volume config data, this is not the streaming export
 * a high-volume table would need (see module README's honest scoping
 * of export/import/backup).
 */
final class SourcesExporter implements ExporterInterface
{
    public function __construct(private readonly SourceRepositoryInterface $sources)
    {
    }

    public function key(): string
    {
        return 'sources';
    }

    public function export(): array
    {
        $page = $this->sources->paginate(1, 10000);

        return [
            'sources' => array_map(static fn ($s): array => [
                'name'    => $s->name,
                'type'    => $s->type,
                'config'  => $s->config,
                'enabled' => $s->enabled,
            ], $page->items),
        ];
    }
}
