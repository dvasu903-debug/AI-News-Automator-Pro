<?php
/**
 * PublishingProfileRepository.
 *
 * REVISION NOTE (r3, Module 3 compatibility audit): completely rewritten
 * from the r2 draft, which was built against an assumed fluent-builder
 * API (`table()->where()->first()` directly on Connection, a
 * `transaction()` method on Connection). The real contracts are
 * different in every one of those respects — see the audit report.
 * This version:
 *   - extends AbstractRepository (the established idiom; QueueRepository
 *     and DraftRepository both do the same), inheriting findRow/
 *     insertRow/updateRow/deleteRow/paginateRows and the validate-
 *     before-persist hook;
 *   - uses ConnectionInterface::newQuery() + Filter/SortOrder value
 *     objects for reads, never raw where()/first() on Connection itself;
 *   - injects TransactionManagerInterface as a separate constructor
 *     dependency (Connection has no transaction() method);
 *   - never includes is_default in the generic update() payload, so the
 *     single-writer invariant for that column (only markDefault() may
 *     change it) is enforced at the repository level too, not only by
 *     service-layer discipline.
 *
 * @extends AbstractRepository<PublishingProfile>
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Repositories;

use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\ProfileNotFoundException;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\TransactionManagerInterface;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\SortOrder;
use AINewsAutomator\Storage\Repositories\AbstractRepository;
use DateTimeImmutable;
use DateTimeZone;

final class PublishingProfileRepository extends AbstractRepository implements PublishingProfileRepositoryInterface
{
    /**
     * Logical table name (see Storage\Database\Tables docblock
     * convention). Publishing owns this table, not Storage, so it is
     * not registered in Storage's own Tables constants class — matching
     * how the frozen migrations already reference it via the literal
     * string 'publishing_profiles' passed to SchemaBuilder::tableName().
     */
    private const TABLE = 'publishing_profiles';

    public function __construct(
        ConnectionInterface $connection,
        private readonly TransactionManagerInterface $transactions
    ) {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function hydrate(array $row): PublishingProfile
    {
        return PublishingProfile::fromArray($row);
    }

    /**
     * Used by insertRow()/insertRows() only. Always includes is_default
     * (new profiles are inserted non-default; PublishingProfileService
     * promotes via markDefault() as a separate step — see Service
     * create()). Never used for update() — see updateFields().
     */
    protected function dehydrate(mixed $entity): array
    {
        /** @var PublishingProfile $entity */
        $now = $this->now();

        return [
            'slug'          => $entity->slug(),
            'name'          => $entity->name(),
            'vertical'      => $entity->vertical(),
            'workflow_key'  => $entity->workflowKey(),
            'approval_mode' => $entity->approvalMode(),
            'config'        => $entity->configJson(),
            'enabled'       => $entity->enabled() ? 1 : 0,
            'is_default'    => $entity->isDefault() ? 1 : 0,
            'created_at'    => $now->format('Y-m-d H:i:s'),
            'updated_at'    => $now->format('Y-m-d H:i:s'),
        ];
    }

    public function create(PublishingProfile $profile): PublishingProfile
    {
        $now = $this->now();
        $id  = $this->insertRow($profile);

        return $profile->withId($id)->withTimestamps($now, $now);
    }

    public function update(PublishingProfile $profile): PublishingProfile
    {
        $id = $profile->id();

        if (null === $id) {
            throw ProfileNotFoundException::forId(0);
        }

        if (null === $this->findRow($id)) {
            throw ProfileNotFoundException::forId($id);
        }

        $now = $this->now();

        // Deliberately excludes is_default — see class docblock.
        $this->updateRow($this->updateFields($profile, $now), ['id' => $id]);

        return $profile->withTimestamps($profile->createdAt(), $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function updateFields(PublishingProfile $profile, DateTimeImmutable $now): array
    {
        return [
            'slug'          => $profile->slug(),
            'name'          => $profile->name(),
            'vertical'      => $profile->vertical(),
            'workflow_key'  => $profile->workflowKey(),
            'approval_mode' => $profile->approvalMode(),
            'config'        => $profile->configJson(),
            'enabled'       => $profile->enabled() ? 1 : 0,
            'updated_at'    => $now->format('Y-m-d H:i:s'),
        ];
    }

    public function delete(int $profileId): bool
    {
        return $this->deleteRow($profileId) > 0;
    }

    public function findById(int $profileId): ?PublishingProfile
    {
        return $this->findRow($profileId);
    }

    public function findBySlug(string $slug): ?PublishingProfile
    {
        $row = $this->connection->newQuery($this->table())
            ->where(Filter::equals('slug', $slug))
            ->first();

        return null === $row ? null : $this->hydrate($row);
    }

    public function findAll(bool $enabledOnly = false): array
    {
        $query = $this->connection->newQuery($this->table())
            ->orderBy(SortOrder::asc('name'));

        if ($enabledOnly) {
            $query = $query->where(Filter::equals('enabled', 1));
        }

        return array_map(
            fn (array $row): PublishingProfile => $this->hydrate($row),
            $query->get()
        );
    }

    public function findDefault(): ?PublishingProfile
    {
        // No implicit fallback — explicit default or null. Policy for
        // "no default configured" belongs to
        // PublishingProfileService::requireDefault().
        $row = $this->connection->newQuery($this->table())
            ->where(Filter::equals('is_default', 1))
            ->first();

        return null === $row ? null : $this->hydrate($row);
    }

    public function markDefault(int $profileId): void
    {
        $this->transactions->transactional(function () use ($profileId): void {
            if (null === $this->findRow($profileId)) {
                throw ProfileNotFoundException::forId($profileId);
            }

            $now = $this->now()->format('Y-m-d H:i:s');

            // Demote is a single blanket exact-match UPDATE (is_default=1),
            // NOT a read-then-targeted-demote. The previous SELECT-first
            // variant was a race under concurrency (runtime checklist item
            // D12): two simultaneous transactions could both snapshot-read
            // the same stale "current default", each demote only that row,
            // and each promote its own target — leaving two defaults. A
            // blanket UPDATE takes row locks on every currently-default row,
            // serializing concurrent markDefault() calls, and re-evaluates
            // against committed data (current read) once a blocking
            // transaction finishes. Demoting the target row itself when it
            // is already default is harmless — the promote below restores it
            // within the same transaction.
            $this->connection->update(
                $this->table(),
                ['is_default' => 0, 'updated_at' => $now],
                ['is_default' => 1]
            );

            $this->connection->update(
                $this->table(),
                ['is_default' => 1, 'updated_at' => $now],
                ['id' => $profileId]
            );
        });
    }

    public function setEnabled(int $profileId, bool $enabled): void
    {
        if (null === $this->findRow($profileId)) {
            throw ProfileNotFoundException::forId($profileId);
        }

        $this->updateRow(
            ['enabled' => $enabled ? 1 : 0, 'updated_at' => $this->now()->format('Y-m-d H:i:s')],
            ['id' => $profileId]
        );
    }

    public function existsWithSlug(string $slug, ?int $excludeId = null): bool
    {
        $filters = [Filter::equals('slug', $slug)];

        if (null !== $excludeId) {
            $filters[] = Filter::notEquals('id', $excludeId);
        }

        // first()!==null rather than count()>0: equivalent existence
        // semantics, LIMIT 1 instead of a full COUNT(*) scan, and
        // testable against tests/Storage/FakeWpdb.php — that fake's
        // get_var() COUNT(*) branch does not apply the WHERE clause at
        // all (a real gap in shared test infra, not something specific
        // to this method; flagged separately, not fixed here since it's
        // outside Publishing's module boundary).
        return null !== $this->connection->newQuery($this->table())
            ->whereAll($filters)
            ->first();
    }

    public function existsWithName(string $name, ?int $excludeId = null): bool
    {
        $filters = [Filter::equals('name', $name)];

        if (null !== $excludeId) {
            $filters[] = Filter::notEquals('id', $excludeId);
        }

        return null !== $this->connection->newQuery($this->table())
            ->whereAll($filters)
            ->first();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
