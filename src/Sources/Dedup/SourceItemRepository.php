<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Dedup;

use AINewsAutomator\Sources\Contracts\SourceItemRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\PurgeableInterface;
use AINewsAutomator\Storage\Database\BatchPurger;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;

/**
 * Persists to the Sources-owned `ana_source_items` table, reusing
 * Storage's AbstractRepository/BatchPurger classes directly (ADR-0006 —
 * Storage is frozen from modification, not from reuse). Implements
 * Storage's own PurgeableInterface so this table participates in the
 * same batched-retention pattern Logs/Audit/Job-History already use.
 *
 * @extends AbstractRepository<SourceItemFingerprint>
 */
final class SourceItemRepository extends AbstractRepository implements SourceItemRepositoryInterface, PurgeableInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return 'source_items';
    }

    protected function hydrate(array $row): SourceItemFingerprint
    {
        return SourceItemFingerprint::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var SourceItemFingerprint $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var SourceItemFingerprint $entity */
        if ($entity->sourceId <= 0) {
            throw new ValidationException(['source_id' => 'A valid source id is required.'], 'Source item fingerprint failed validation.');
        }

        if (strlen($entity->fingerprint) !== 64) {
            // sha256 hex digest length — catches a caller accidentally
            // passing an unhashed value.
            throw new ValidationException(['fingerprint' => 'Fingerprint must be a 64-character sha256 hex digest.'], 'Source item fingerprint failed validation.');
        }
    }

    public function find(int $sourceId, string $fingerprint): ?SourceItemFingerprint
    {
        $row = $this->connection->newQuery($this->table())
            ->whereAll([Filter::equals('source_id', $sourceId), Filter::equals('fingerprint', $fingerprint)])
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function upsert(SourceItemFingerprint $item): void
    {
        $existing = $this->find($item->sourceId, $item->fingerprint);

        if ($existing === null) {
            $this->insertRow($item);
            return;
        }

        $this->validate($item);
        $this->updateRow(
            ['last_seen' => EntityDates::toMysql($item->lastSeen), 'status' => $item->status->value],
            ['id' => $existing->id]
        );
    }

    public function purgeOlderThan(int $days): int
    {
        return BatchPurger::purgeOlderThan($this->connection, $this->table(), 'last_seen', $days);
    }
}
