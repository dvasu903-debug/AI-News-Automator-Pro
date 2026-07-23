<?php
/**
 * PublishingProfileRepositoryTest.
 *
 * Exercises PublishingProfileRepository against the REAL Connection and
 * TransactionManager classes, backed by tests/Storage/FakeWpdb.php —
 * the same pattern tests/Storage/AbstractRepositoryTest.php uses. This
 * is a stronger guarantee than an in-memory double: it proves the
 * repository's generated SQL actually round-trips through Connection/
 * QueryBuilder correctly, which is exactly the class of thing an
 * assumed-API double cannot catch (see the Module 3 compatibility audit
 * that produced this rewrite).
 *
 * KNOWN GAP (flagged, not silently worked around): existsWithSlug()/
 * existsWithName()'s $excludeId path generates a Filter::notEquals()
 * condition (SQL `!=`), which tests/Storage/FakeWpdb.php's applyWhere()
 * does not recognize (only =, <=, IN are implemented there — see that
 * file). The no-excludeId path is fully tested here since it only uses
 * `=`. The excludeId path cannot be verified until either FakeWpdb gains
 * `!=` support (a shared-test-infra change outside this module's
 * boundary, recommended but not made here) or it's verified against a
 * real MySQL instance during the Hostinger runtime validation phase,
 * where it's covered by MILESTONE_2_FREEZE_CHECKLIST.md item D7's
 * uniqueness verification.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\ProfileNotFoundException;
use AINewsAutomator\Publishing\Repositories\PublishingProfileRepository;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Database\TransactionManager;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class PublishingProfileRepositoryTest extends TestCase
{
    private FakeWpdb $wpdb;
    private PublishingProfileRepository $repository;

    protected function setUp(): void
    {
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // Matches SchemaBuilder::tableName('publishing_profiles') with
        // FakeWpdb's default prefix ('wp_').
        $this->wpdb->createTable('wp_ana_publishing_profiles');

        /** @var ConnectionInterface $connection */
        $connection = new Connection();

        $this->repository = new PublishingProfileRepository($connection, new TransactionManager());
    }

    public function test_create_assigns_id_and_persists_real_columns(): void
    {
        $created = $this->repository->create($this->makeProfile('alpha', 'Alpha'));

        $this->assertSame(1, $created->id());
        $this->assertNotNull($created->createdAt());

        $found = $this->repository->findById(1);
        $this->assertNotNull($found);
        $this->assertSame('alpha', $found->slug());
        $this->assertSame('tech', $found->vertical());
        $this->assertSame('tech.article.v1', $found->workflowKey());
        $this->assertSame('manual', $found->approvalMode());
        $this->assertFalse($found->isDefault(), 'New profiles must not be inserted as default.');
    }

    public function test_find_by_id_returns_null_for_missing_row(): void
    {
        $this->assertNull($this->repository->findById(999));
    }

    public function test_find_by_slug(): void
    {
        $this->repository->create($this->makeProfile('alpha', 'Alpha'));

        $found = $this->repository->findBySlug('alpha');

        $this->assertNotNull($found);
        $this->assertSame('Alpha', $found->name());
    }

    public function test_find_by_slug_returns_null_when_absent(): void
    {
        $this->assertNull($this->repository->findBySlug('nonexistent'));
    }

    public function test_update_persists_changes_and_never_touches_is_default(): void
    {
        $created = $this->repository->create($this->makeProfile('alpha', 'Alpha', true));
        $this->repository->markDefault((int) $created->id());

        // Update attempts to smuggle is_default=false through the DTO;
        // the repository's update() must silently ignore that field —
        // is_default is single-writer via markDefault() only.
        $updated = $this->repository->update(
            $this->repository->findById((int) $created->id())->withName('Alpha Renamed')->withDefault(false)
        );

        $this->assertSame('Alpha Renamed', $updated->name());

        $reloaded = $this->repository->findById((int) $created->id());
        $this->assertTrue($reloaded->isDefault(), 'update() must never modify is_default.');
    }

    public function test_update_nonexistent_profile_throws(): void
    {
        $ghost = $this->makeProfile('ghost', 'Ghost')->withId(999);

        $this->expectException(ProfileNotFoundException::class);
        $this->repository->update($ghost);
    }

    public function test_delete_removes_row(): void
    {
        $created = $this->repository->create($this->makeProfile('alpha', 'Alpha'));

        $this->assertTrue($this->repository->delete((int) $created->id()));
        $this->assertNull($this->repository->findById((int) $created->id()));
    }

    public function test_delete_returns_false_for_missing_row(): void
    {
        $this->assertFalse($this->repository->delete(999));
    }

    public function test_find_all_returns_every_profile(): void
    {
        $this->repository->create($this->makeProfile('alpha', 'Alpha'));
        $this->repository->create($this->makeProfile('beta', 'Beta'));

        $this->assertCount(2, $this->repository->findAll());
    }

    public function test_find_all_enabled_only_filters_disabled(): void
    {
        $alpha = $this->repository->create($this->makeProfile('alpha', 'Alpha'));
        $this->repository->create($this->makeProfile('beta', 'Beta'));
        $this->repository->setEnabled((int) $alpha->id(), false);

        $enabled = $this->repository->findAll(true);

        $this->assertCount(1, $enabled);
        $this->assertSame('beta', $enabled[0]->slug());
    }

    public function test_set_enabled_toggles_flag(): void
    {
        $created = $this->repository->create($this->makeProfile('alpha', 'Alpha'));

        $this->repository->setEnabled((int) $created->id(), false);
        $this->assertFalse($this->repository->findById((int) $created->id())->enabled());

        $this->repository->setEnabled((int) $created->id(), true);
        $this->assertTrue($this->repository->findById((int) $created->id())->enabled());
    }

    public function test_set_enabled_unknown_id_throws(): void
    {
        $this->expectException(ProfileNotFoundException::class);
        $this->repository->setEnabled(999, false);
    }

    public function test_find_default_returns_null_when_none_marked(): void
    {
        $this->repository->create($this->makeProfile('alpha', 'Alpha'));

        $this->assertNull($this->repository->findDefault());
    }

    public function test_mark_default_promotes_and_demotes_atomically(): void
    {
        $alpha = $this->repository->create($this->makeProfile('alpha', 'Alpha'));
        $beta  = $this->repository->create($this->makeProfile('beta', 'Beta'));

        $this->repository->markDefault((int) $alpha->id());
        $this->assertTrue($this->repository->findById((int) $alpha->id())->isDefault());

        $this->repository->markDefault((int) $beta->id());

        $this->assertFalse(
            $this->repository->findById((int) $alpha->id())->isDefault(),
            'Promoting a new default must demote the previous one.'
        );
        $this->assertTrue($this->repository->findById((int) $beta->id())->isDefault());
        $this->assertNotNull($this->repository->findDefault());
        $this->assertSame('beta', $this->repository->findDefault()->slug());
    }

    public function test_mark_default_unknown_id_throws(): void
    {
        $this->expectException(ProfileNotFoundException::class);
        $this->repository->markDefault(999);
    }

    public function test_exists_with_slug_true_when_present(): void
    {
        $this->repository->create($this->makeProfile('alpha', 'Alpha'));

        $this->assertTrue($this->repository->existsWithSlug('alpha'));
    }

    public function test_exists_with_slug_false_when_absent(): void
    {
        $this->assertFalse($this->repository->existsWithSlug('nonexistent'));
    }

    public function test_exists_with_name_true_when_present(): void
    {
        $this->repository->create($this->makeProfile('alpha', 'Alpha'));

        $this->assertTrue($this->repository->existsWithName('Alpha'));
    }

    /**
     * See class docblock "KNOWN GAP". Documents the coverage boundary
     * rather than silently skipping it or asserting something the fake
     * cannot actually prove.
     */
    public function test_exists_with_slug_exclude_id_needs_real_mysql_verification(): void
    {
        $this->markTestIncomplete(
            'tests/Storage/FakeWpdb.php::applyWhere() does not support the '
            . '"!=" operator that Filter::notEquals() generates for the '
            . 'excludeId path. Verify against a real MySQL instance during '
            . 'Hostinger runtime validation instead (see '
            . 'MILESTONE_2_FREEZE_CHECKLIST.md item D7).'
        );
    }

    private function makeProfile(string $slug, string $name, bool $default = false): PublishingProfile
    {
        return PublishingProfile::fromArray([
            'slug'          => $slug,
            'name'          => $name,
            'vertical'      => 'tech',
            'workflow_key'  => 'tech.article.v1',
            'approval_mode' => 'manual',
            'enabled'       => true,
            'is_default'    => $default,
            'config'        => ['schema_version' => 1],
        ]);
    }
}
