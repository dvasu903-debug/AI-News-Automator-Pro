<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Imports a payload previously produced by the matching ExporterInterface.
 */
interface ImporterInterface
{
    public function key(): string;

    /**
     * @param array<string, mixed> $payload
     * @return int Number of records imported.
     *
     * @throws \AINewsAutomator\Storage\Exceptions\ValidationException If the payload is malformed.
     */
    public function import(array $payload): int;
}
