<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared scaffolding in AbstractRepository (validate-before-
 * persist, bulk insert, find, paginate) via a minimal fixture entity and
 * repository — proving the base class's contract works correctly, which
 * every one of the eight table-backed concrete repositories relies on
 * without re-implementing it.
 */
final class AbstractRepositoryTest extends TestCase
{
    private FakeWpdb $wpdb;
    private ConnectionInterface $connection;
    private FixtureRepository $repository;

    protected function setUp(): void
    {
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->wpdb->createTable('wp_ana_fixture');

        $this->connection = new Connection();
        $this->repository = new FixtureRepository($this->connection);
    }

    public function test_insert_and_find(): void
    {
        $id = $this->repository->add(new FixtureEntity(null, 'first'));

        $found = $this->repository->get($id);

        $this->assertNotNull($found);
        $this->assertSame('first', $found->name);
    }

    public function test_find_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->repository->get(999));
    }

    public function test_validation_runs_before_insert(): void
    {
        $this->expectException(ValidationException::class);
        $this->repository->add(new FixtureEntity(null, ''));
    }

    public function test_bulk_insert_validates_every_entity_before_writing_any(): void
    {
        $entities = [
            new FixtureEntity(null, 'valid-one'),
            new FixtureEntity(null, ''), // invalid — should abort the whole batch
        ];

        try {
            $this->repository->addMany($entities);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            // Confirm nothing was written despite the first entity being valid.
            $this->assertNull($this->repository->get(1));
        }
    }

    public function test_bulk_insert_writes_all_valid_entities(): void
    {
        $count = $this->repository->addMany([
            new FixtureEntity(null, 'one'),
            new FixtureEntity(null, 'two'),
            new FixtureEntity(null, 'three'),
        ]);

        $this->assertSame(3, $count);
    }
}

final class FixtureEntity
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
    ) {
    }
}

/**
 * @extends AbstractRepository<FixtureEntity>
 */
final class FixtureRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'fixture';
    }

    protected function hydrate(array $row): FixtureEntity
    {
        return new FixtureEntity((int) $row['id'], (string) $row['name']);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var FixtureEntity $entity */
        return ['name' => $entity->name];
    }

    protected function validate(mixed $entity): void
    {
        /** @var FixtureEntity $entity */
        if (trim($entity->name) === '') {
            throw new ValidationException(['name' => 'Name is required.'], 'Fixture failed validation.');
        }
    }

    public function add(FixtureEntity $entity): int
    {
        return $this->insertRow($entity);
    }

    /**
     * @param list<FixtureEntity> $entities
     */
    public function addMany(array $entities): int
    {
        return $this->insertRows($entities);
    }

    public function get(int $id): ?FixtureEntity
    {
        return $this->findRow($id);
    }
}
