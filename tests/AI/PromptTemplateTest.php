<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\Exceptions\AIValidationException;
use AINewsAutomator\AI\Prompt\PromptRenderer;
use AINewsAutomator\AI\Prompt\PromptTemplate;
use AINewsAutomator\AI\Prompt\PromptTemplateRepository;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class PromptTemplateTest extends TestCase
{
    private FakeWpdb $wpdb;
    private PromptTemplateRepository $repository;

    protected function setUp(): void
    {
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->wpdb->createTable('wp_ana_prompt_templates');

        $this->repository = new PromptTemplateRepository(new Connection());
    }

    /**
     * @param array<string, mixed> $variablesSchema
     */
    private function template(
        string $name = 'fact-check',
        string $version = '1.0.0',
        string $text = 'Verify: {{claim}}',
        array $variablesSchema = ['required' => ['claim']],
    ): PromptTemplate {
        return new PromptTemplate(
            id: null,
            name: $name,
            version: $version,
            vertical: 'news',
            templateText: $text,
            variablesSchema: $variablesSchema,
            createdAt: EntityDates::now(),
        );
    }

    public function test_is_valid_semver_accepts_correct_format(): void
    {
        $this->assertTrue($this->template(version: '1.2.3')->isValidSemver());
    }

    public function test_is_valid_semver_rejects_incorrect_format(): void
    {
        $this->assertFalse($this->template(version: 'v1')->isValidSemver());
        $this->assertFalse($this->template(version: '1.0')->isValidSemver());
        $this->assertFalse($this->template(version: 'latest')->isValidSemver());
    }

    public function test_save_new_version_rejects_invalid_semver(): void
    {
        $this->expectException(ValidationException::class);
        $this->repository->saveNewVersion($this->template(version: 'not-semver'));
    }

    public function test_save_and_retrieve_a_version(): void
    {
        $this->repository->saveNewVersion($this->template(version: '1.0.0'));

        $retrieved = $this->repository->getVersion('fact-check', '1.0.0');

        $this->assertNotNull($retrieved);
        $this->assertSame('1.0.0', $retrieved->version);
    }

    public function test_cannot_overwrite_an_existing_version(): void
    {
        $this->repository->saveNewVersion($this->template(version: '1.0.0', text: 'first'));

        $this->expectException(ValidationException::class);
        $this->repository->saveNewVersion($this->template(version: '1.0.0', text: 'attempted overwrite'));
    }

    public function test_get_latest_returns_highest_semver_not_most_recent_insert(): void
    {
        // Insert out of order: 1.0.0, then 2.0.0, then 1.5.0 — "latest" must
        // be 2.0.0 by semver value, not by insertion order.
        $this->repository->saveNewVersion($this->template(version: '1.0.0'));
        $this->repository->saveNewVersion($this->template(version: '2.0.0'));
        $this->repository->saveNewVersion($this->template(version: '1.5.0'));

        $latest = $this->repository->getLatest('fact-check');

        $this->assertSame('2.0.0', $latest?->version);
    }

    public function test_semver_ordering_is_numeric_not_lexicographic(): void
    {
        // Lexicographically "10.0.0" < "2.0.0" (because '1' < '2'), but
        // numerically 10.0.0 is newer — history() must sort correctly.
        $this->repository->saveNewVersion($this->template(version: '2.0.0'));
        $this->repository->saveNewVersion($this->template(version: '10.0.0'));

        $history = $this->repository->history('fact-check');

        $this->assertSame('10.0.0', $history[0]->version, 'Newest-first must be numeric semver order.');
    }

    public function test_history_returns_all_versions_for_a_template(): void
    {
        $this->repository->saveNewVersion($this->template(version: '1.0.0'));
        $this->repository->saveNewVersion($this->template(version: '1.1.0'));

        $this->assertCount(2, $this->repository->history('fact-check'));
    }

    public function test_different_template_names_are_independent(): void
    {
        $this->repository->saveNewVersion($this->template(name: 'fact-check', version: '1.0.0'));
        $this->repository->saveNewVersion($this->template(name: 'seo-title', version: '1.0.0'));

        $this->assertCount(1, $this->repository->history('fact-check'));
        $this->assertCount(1, $this->repository->history('seo-title'));
    }

    public function test_renderer_substitutes_variables(): void
    {
        $renderer = new PromptRenderer();
        $template = $this->template(
            text: 'Verify claim: {{claim}} from {{source}}',
            variablesSchema: ['required' => ['claim', 'source']],
        );

        $result = $renderer->render($template, ['claim' => 'the sky is blue', 'source' => 'wikipedia']);

        $this->assertSame('Verify claim: the sky is blue from wikipedia', $result);
    }

    public function test_renderer_throws_on_missing_required_variable(): void
    {
        $renderer = new PromptRenderer();

        $this->expectException(AIValidationException::class);
        $renderer->render($this->template(), []); // missing required "claim"
    }
}
