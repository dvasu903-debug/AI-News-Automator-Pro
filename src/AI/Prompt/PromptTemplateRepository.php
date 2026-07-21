<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Prompt;

use AINewsAutomator\AI\Contracts\PromptTemplateRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;

/**
 * Persists prompt templates to an AI-module-owned table
 * (`ana_prompt_templates`), reusing Storage's AbstractRepository class
 * directly rather than duplicating pagination/hydration scaffolding —
 * "Storage is frozen from modification, not from reuse."
 *
 * Enforces write-once versioning: saveNewVersion() refuses to insert a
 * duplicate (name, version) pair. Semver-correct ordering (getLatest,
 * history) uses PHP's version_compare() rather than SQL ORDER BY on the
 * version string, because lexicographic string sort is wrong for semver
 * ("10.0.0" sorts before "2.0.0" as plain text).
 *
 * @extends AbstractRepository<PromptTemplate>
 */
final class PromptTemplateRepository extends AbstractRepository implements PromptTemplateRepositoryInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return 'prompt_templates';
    }

    protected function hydrate(array $row): PromptTemplate
    {
        return PromptTemplate::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var PromptTemplate $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var PromptTemplate $entity */
        $errors = [];

        if (trim($entity->name) === '') {
            $errors['name'] = 'Template name is required.';
        }

        if (!$entity->isValidSemver()) {
            $errors['version'] = sprintf('Version "%s" is not valid semantic versioning (expected MAJOR.MINOR.PATCH).', $entity->version);
        }

        if (trim($entity->templateText) === '') {
            $errors['template_text'] = 'Template text is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Prompt template failed validation.');
        }
    }

    public function getLatest(string $name): ?PromptTemplate
    {
        $versions = $this->allVersionsSorted($name);

        return $versions[0] ?? null;
    }

    public function getVersion(string $name, string $version): ?PromptTemplate
    {
        $row = $this->connection->newQuery($this->table())
            ->whereAll([Filter::equals('name', $name), Filter::equals('version', $version)])
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function history(string $name): array
    {
        return $this->allVersionsSorted($name);
    }

    public function saveNewVersion(PromptTemplate $template): int
    {
        if ($this->getVersion($template->name, $template->version) !== null) {
            throw new ValidationException(
                ['version' => sprintf('Version "%s" of template "%s" already exists — versions are write-once.', $template->version, $template->name)],
                'Cannot overwrite an existing prompt template version.'
            );
        }

        return $this->insertRow($template);
    }

    /**
     * @return list<PromptTemplate> Newest version first, by semver — not insertion order.
     */
    private function allVersionsSorted(string $name): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('name', $name))
            ->get();

        $templates = array_map(fn (array $row) => $this->hydrate($row), $rows);

        usort($templates, static fn (PromptTemplate $a, PromptTemplate $b): int => version_compare($b->version, $a->version));

        return $templates;
    }
}
