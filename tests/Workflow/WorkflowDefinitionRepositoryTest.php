<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow;

use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use AINewsAutomator\Workflow\Entities\WorkflowDefinitionVersion;
use AINewsAutomator\Workflow\Repositories\WorkflowDefinitionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Enforces Part 1 / Option A: write-once versioning. The single most
 * important behavioral guarantee of this module's storage layer, so it
 * gets its own dedicated test file rather than being folded into
 * WorkflowRunnerTest.
 */
final class WorkflowDefinitionRepositoryTest extends TestCase
{
    private WorkflowDefinitionRepository $repo;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->createTable('wp_ana_workflow_definitions');

        $this->repo = new WorkflowDefinitionRepository(new Connection());
    }

    private function version(string $key, int $version, array $definition = ['steps' => [], 'trigger' => ['type' => 'manual']]): WorkflowDefinitionVersion
    {
        return new WorkflowDefinitionVersion(null, $key, $version, $definition, EntityDates::now());
    }

    public function test_saves_and_finds_a_version(): void
    {
        $this->repo->saveNewVersion($this->version('demo', 1));

        $found = $this->repo->findVersion('demo', 1);

        $this->assertNotNull($found);
        $this->assertSame(1, $found->version);
    }

    public function test_saving_a_duplicate_version_throws(): void
    {
        $this->repo->saveNewVersion($this->version('demo', 1));

        $this->expectException(ValidationException::class);
        $this->repo->saveNewVersion($this->version('demo', 1));
    }

    public function test_there_is_no_update_path_only_saveNewVersion(): void
    {
        // Structural guarantee, not just a runtime one: the interface
        // itself exposes no update()/save()-over-existing-id method.
        $this->assertFalse(method_exists($this->repo, 'update'));
        $this->assertFalse(method_exists($this->repo, 'save'));
    }

    public function test_latest_returns_the_highest_version(): void
    {
        $this->repo->saveNewVersion($this->version('demo', 1));
        $this->repo->saveNewVersion($this->version('demo', 2));
        $this->repo->saveNewVersion($this->version('demo', 3));

        $latest = $this->repo->latest('demo');

        $this->assertSame(3, $latest->version);
    }

    public function test_history_returns_newest_first(): void
    {
        $this->repo->saveNewVersion($this->version('demo', 1));
        $this->repo->saveNewVersion($this->version('demo', 2));

        $history = $this->repo->history('demo');

        $this->assertSame([2, 1], array_map(static fn ($v) => $v->version, $history));
    }

    public function test_all_keys_returns_distinct_workflow_keys(): void
    {
        $this->repo->saveNewVersion($this->version('demo-a', 1));
        $this->repo->saveNewVersion($this->version('demo-a', 2));
        $this->repo->saveNewVersion($this->version('demo-b', 1));

        $keys = $this->repo->allKeys();
        sort($keys);

        $this->assertSame(['demo-a', 'demo-b'], $keys);
    }

    public function test_empty_definition_fails_validation(): void
    {
        $this->expectException(ValidationException::class);
        $this->repo->saveNewVersion($this->version('demo', 1, []));
    }

    public function test_latest_returns_null_for_unknown_key(): void
    {
        $this->assertNull($this->repo->latest('does-not-exist'));
    }
}
