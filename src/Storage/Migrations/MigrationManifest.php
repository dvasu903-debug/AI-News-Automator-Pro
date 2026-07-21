<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations;

use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000001_CreateSchemaMigrationsTable;
use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000002_CreateQueueAndJobsTables;
use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000003_CreateLogsTable;
use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000004_CreateAuditTable;
use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000005_CreateMetricsTables;
use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000006_CreateSourcesTable;
use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000007_CreateWorkflowsTable;
use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000008_CreateAiRequestsTable;
use AINewsAutomator\Storage\Migrations\Versions\Migration_20260714000009_CreateImagesTable;

/**
 * The explicit, ordered list of every migration this module owns —
 * mirrors ModuleManifest's own pattern (predictable order, testable, no
 * filesystem directory-scanning inside a distributed plugin ZIP). Adding
 * a future migration means appending one line here; MigrationRunner
 * determines what's actually pending by comparing against recorded
 * history, so list order here doesn't need to match application order
 * (the runner sorts by version()), but conventionally new entries are
 * appended at the end for readability.
 */
final class MigrationManifest
{
    /**
     * @return list<\AINewsAutomator\Storage\Contracts\MigrationInterface>
     */
    public static function migrations(): array
    {
        return [
            new Migration_20260714000001_CreateSchemaMigrationsTable(),
            new Migration_20260714000002_CreateQueueAndJobsTables(),
            new Migration_20260714000003_CreateLogsTable(),
            new Migration_20260714000004_CreateAuditTable(),
            new Migration_20260714000005_CreateMetricsTables(),
            new Migration_20260714000006_CreateSourcesTable(),
            new Migration_20260714000007_CreateWorkflowsTable(),
            new Migration_20260714000008_CreateAiRequestsTable(),
            new Migration_20260714000009_CreateImagesTable(),
        ];
    }
}
