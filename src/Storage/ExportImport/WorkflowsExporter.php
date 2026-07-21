<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\ExportImport;

use AINewsAutomator\Storage\Contracts\ExporterInterface;
use AINewsAutomator\Storage\Contracts\WorkflowRepositoryInterface;

final class WorkflowsExporter implements ExporterInterface
{
    public function __construct(private readonly WorkflowRepositoryInterface $workflows)
    {
    }

    public function key(): string
    {
        return 'workflows';
    }

    public function export(): array
    {
        // No "all verticals" listing on the interface (it's scoped per-
        // vertical by design); export what's available for the shipping
        // "news" vertical now. A future multi-vertical export can extend
        // this once more than one vertical actually exists.
        $workflows = $this->workflows->forVertical('news', enabledOnly: false);

        return [
            'workflows' => array_map(static fn ($w): array => [
                'name'       => $w->name,
                'vertical'   => $w->vertical,
                'definition' => $w->definition,
                'enabled'    => $w->enabled,
            ], $workflows),
        ];
    }
}
