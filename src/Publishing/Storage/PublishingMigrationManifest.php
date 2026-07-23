<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Storage;

use AINewsAutomator\Publishing\Storage\Migrations\Migration_20260722100001_CreatePublishingProfilesTable;
use AINewsAutomator\Publishing\Storage\Migrations\Migration_20260722100002_CreatePublishingRunsTable;
use AINewsAutomator\Publishing\Storage\Migrations\Migration_20260722100003_CreateDraftSeoTable;

/**
 * Publishing's own explicit, ordered migration list — mirrors AI's,
 * Sources', Research's, and Workflow's manifest pattern exactly
 * (ADR-0006). Applied through the same shared MigrationRunner singleton
 * Storage registered; no new runner instance needed.
 */
final class PublishingMigrationManifest
{
    /**
     * @return list<\AINewsAutomator\Storage\Contracts\MigrationInterface>
     */
    public static function migrations(): array
    {
        return [
            new Migration_20260722100001_CreatePublishingProfilesTable(),
            new Migration_20260722100002_CreatePublishingRunsTable(),
            new Migration_20260722100003_CreateDraftSeoTable(),
        ];
    }
}
