<?php
/**
 * Shared test double for PromptTemplateRepositoryInterface, used by
 * AiContentGeneratorTest.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\AI\Contracts\PromptTemplateRepositoryInterface;
use AINewsAutomator\AI\Prompt\PromptTemplate;

final class FakePromptTemplateRepository implements PromptTemplateRepositoryInterface
{
    public ?PromptTemplate $latest = null;

    public function getLatest(string $name): ?PromptTemplate
    {
        return $this->latest;
    }

    public function getVersion(string $name, string $version): ?PromptTemplate
    {
        return $this->latest;
    }

    public function history(string $name): array
    {
        return $this->latest !== null ? [$this->latest] : [];
    }

    public function saveNewVersion(PromptTemplate $template): int
    {
        $this->latest = $template;
        return 1;
    }
}
