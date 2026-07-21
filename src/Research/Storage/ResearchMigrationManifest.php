<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Storage;

use AINewsAutomator\Research\Storage\Migrations\Migration_20260714300001_CreateResearchSessionsTable;
use AINewsAutomator\Research\Storage\Migrations\Migration_20260714300002_CreateResearchEvidenceTable;
use AINewsAutomator\Research\Storage\Migrations\Migration_20260714300003_CreateResearchClaimsTable;
use AINewsAutomator\Research\Storage\Migrations\Migration_20260714300004_CreateResearchClaimEvidenceTable;
use AINewsAutomator\Research\Storage\Migrations\Migration_20260714300005_CreateResearchEntitiesTable;
use AINewsAutomator\Research\Storage\Migrations\Migration_20260714300006_CreateResearchCitationsTable;
use AINewsAutomator\Research\Storage\Migrations\Migration_20260714300007_CreateResearchContradictionsTable;

/**
 * Research's own explicit, ordered migration list — mirrors AI's and
 * Sources' manifest pattern exactly (ADR-0006). Applied through the same
 * shared MigrationRunner singleton Storage registered; no new runner
 * instance needed, since migrate() takes its migration list per call.
 */
final class ResearchMigrationManifest
{
    /**
     * @return list<\AINewsAutomator\Storage\Contracts\MigrationInterface>
     */
    public static function migrations(): array
    {
        return [
            new Migration_20260714300001_CreateResearchSessionsTable(),
            new Migration_20260714300002_CreateResearchEvidenceTable(),
            new Migration_20260714300003_CreateResearchClaimsTable(),
            new Migration_20260714300004_CreateResearchClaimEvidenceTable(),
            new Migration_20260714300005_CreateResearchEntitiesTable(),
            new Migration_20260714300006_CreateResearchCitationsTable(),
            new Migration_20260714300007_CreateResearchContradictionsTable(),
        ];
    }
}
