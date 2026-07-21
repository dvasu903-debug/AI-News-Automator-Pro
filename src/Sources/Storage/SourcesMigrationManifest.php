<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Storage;

use AINewsAutomator\Sources\Storage\Migrations\Migration_20260714200001_CreateSourceItemsTable;

/**
 * Sources' own explicit, ordered migration list — mirrors Storage's and
 * AI's manifest pattern exactly (ADR-0006). Applied through the shared
 * MigrationRunner singleton Storage already registered in the container;
 * no new MigrationRunner instance needed since it takes its migration
 * list as a per-call parameter.
 */
final class SourcesMigrationManifest
{
    /**
     * @return list<\AINewsAutomator\Storage\Contracts\MigrationInterface>
     */
    public static function migrations(): array
    {
        return [
            new Migration_20260714200001_CreateSourceItemsTable(),
        ];
    }
}
