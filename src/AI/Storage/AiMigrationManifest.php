<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Storage;

use AINewsAutomator\AI\Storage\Migrations\Migration_20260714100001_CreatePromptTemplatesTable;
use AINewsAutomator\AI\Storage\Migrations\Migration_20260714100002_CreatePromptHistoryTable;

/**
 * The AI module's own explicit, ordered migration list — mirrors
 * Storage\Migrations\MigrationManifest's pattern exactly, but is a
 * completely separate list applied through a separate MigrationRunner
 * instance (constructed in AIServiceProvider). Storage's own manifest and
 * runner are never touched or shared; both modules simply use the same
 * REUSABLE MigrationRunner/MigrationRecorder/Connection CLASSES.
 */
final class AiMigrationManifest
{
    /**
     * @return list<\AINewsAutomator\Storage\Contracts\MigrationInterface>
     */
    public static function migrations(): array
    {
        return [
            new Migration_20260714100001_CreatePromptTemplatesTable(),
            new Migration_20260714100002_CreatePromptHistoryTable(),
        ];
    }
}
