<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\Prompt\PromptTemplate;

/**
 * Durable, semantically-versioned prompt template storage. Versions are
 * immutable — save() always inserts a new version row; there is no
 * update-in-place path, so a previous version is never overwritten (an
 * explicit approved requirement, enforced here rather than left to
 * caller discipline).
 */
interface PromptTemplateRepositoryInterface
{
    public function getLatest(string $name): ?PromptTemplate;

    public function getVersion(string $name, string $version): ?PromptTemplate;

    /**
     * @return list<PromptTemplate> Every stored version, newest first.
     */
    public function history(string $name): array;

    /**
     * Persists a NEW version. Throws if (name, version) already exists —
     * versions are write-once.
     */
    public function saveNewVersion(PromptTemplate $template): int;
}
