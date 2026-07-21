<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Exports a repository's data to a portable format. Implemented now only
 * for low-volume, config-shaped data (Settings, Sources, Workflows) — see
 * module README for why high-volume tables (logs/audit/AI requests) are
 * an open extension point rather than implemented here.
 */
interface ExporterInterface
{
    /** A stable key identifying what this exporter handles, e.g. "sources". */
    public function key(): string;

    /**
     * @return array<string, mixed> A JSON-serializable export payload.
     */
    public function export(): array;
}
