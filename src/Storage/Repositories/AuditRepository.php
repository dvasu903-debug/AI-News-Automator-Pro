<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Security\Audit\AuditEntry;
use AINewsAutomator\Security\Contracts\AuditLogRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\PurgeableInterface;
use AINewsAutomator\Storage\Database\BatchPurger;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Query\SortOrder;

/**
 * Table-backed replacement for Security's OptionBackedAuditRepository.
 * Implements Security's OWN interface (AuditLogRepositoryInterface,
 * defined in Module 2) rather than a new Storage-owned contract — Security
 * already anticipated this exact hand-off. AuditLogger (Security) depends
 * on the interface, never on this class, so nothing in Security changes;
 * only the container binding does (see StorageServiceProvider).
 *
 * Also implements Storage's own PurgeableInterface for age-based retention
 * — a capability beyond what Security's interface defines, since Security
 * has no concept of a retention policy; RetentionPolicy depends on this
 * additional interface, not Security's.
 *
 * AuditEntry's own shape uses a unix `timestamp` int and a raw `context`
 * array (see Security\Audit\AuditEntry::toArray/fromArray); this
 * repository bridges that to the DB row's `created_at` DATETIME string and
 * JSON-encoded `context` column — the only place that translation happens.
 *
 * @extends AbstractRepository<AuditEntry>
 */
final class AuditRepository extends AbstractRepository implements AuditLogRepositoryInterface, PurgeableInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return Tables::AUDIT;
    }

    protected function hydrate(array $row): AuditEntry
    {
        $data = $row;
        $data['timestamp'] = strtotime((string) $row['created_at']) ?: 0;
        $data['context'] = is_string($row['context'] ?? null) ? (json_decode($row['context'], true) ?: []) : [];

        return AuditEntry::fromArray($data);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var AuditEntry $entity */
        $data = $entity->toArray();

        $row = $data;
        unset($row['timestamp']);
        $row['context'] = wp_json_encode($data['context']) ?: '{}';
        $row['created_at'] = EntityDates::toMysql((new \DateTimeImmutable('@' . $data['timestamp']))->setTimezone(new \DateTimeZone('UTC')));

        return $row;
    }

    public function persist(AuditEntry $entry): void
    {
        $this->insertRow($entry);
    }

    public function recent(int $limit): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->orderBy(SortOrder::desc('id'))
            ->limit($limit)
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function purge(): void
    {
        $this->connection->statement('TRUNCATE TABLE `' . $this->connection->table($this->table()) . '`');
    }

    public function purgeOlderThan(int $days): int
    {
        return BatchPurger::purgeOlderThan($this->connection, $this->table(), 'created_at', $days);
    }
}
