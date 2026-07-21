<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\SourceRecord;
use AINewsAutomator\Storage\Events\SourceSavedEvent;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;

/**
 * @extends AbstractRepository<SourceRecord>
 */
final class SourceRepository extends AbstractRepository implements SourceRepositoryInterface
{
    private const VALID_TYPES = ['rss', 'newsapi', 'google_news', 'youtube', 'github', 'reddit', 'producthunt', 'custom'];

    public function __construct(
        ConnectionInterface $connection,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return Tables::SOURCES;
    }

    protected function hydrate(array $row): SourceRecord
    {
        return SourceRecord::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var SourceRecord $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var SourceRecord $entity */
        $errors = [];

        if (trim($entity->name) === '') {
            $errors['name'] = 'Name is required.';
        }

        if (!in_array($entity->type, self::VALID_TYPES, true)) {
            $errors['type'] = 'Unrecognized source type: ' . $entity->type;
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Source failed validation.');
        }
    }

    public function find(int $id): ?SourceRecord
    {
        return $this->findRow($id);
    }

    public function paginate(int $page = 1, int $perPage = 25, ?string $type = null, ?bool $enabled = null): PageResult
    {
        $filters = [];
        if ($type !== null) {
            $filters[] = Filter::equals('type', $type);
        }
        if ($enabled !== null) {
            $filters[] = Filter::equals('enabled', $enabled ? 1 : 0);
        }

        return $this->paginateRows($filters, [], $page, $perPage);
    }

    public function save(SourceRecord $source): int
    {
        $wasCreated = $source->id === null;
        $now = EntityDates::now();

        $toSave = new SourceRecord(
            id: $source->id,
            name: $source->name,
            type: $source->type,
            config: $source->config,
            enabled: $source->enabled,
            lastFetchedAt: $source->lastFetchedAt,
            lastError: $source->lastError,
            createdAt: $source->createdAt->getTimestamp() === 0 ? $now : $source->createdAt,
            updatedAt: $now,
        );

        if ($wasCreated) {
            $id = $this->insertRow($toSave);
        } else {
            $this->validate($toSave);
            $this->updateRow($this->dehydrate($toSave), ['id' => $source->id]);
            $id = (int) $source->id;
        }

        $this->events->dispatch(new SourceSavedEvent(
            $this->metadataFactory->create('Storage', ['source_id' => $id]),
            sourceId: $id,
            type: $source->type,
            wasCreated: $wasCreated,
        ));

        return $id;
    }

    public function delete(int $id): bool
    {
        return $this->deleteRow($id) > 0;
    }

    public function dueForFetch(): array
    {
        // "Due" means enabled and either never fetched, or last fetched
        // over an hour ago — a simple default cadence; per-source cadence
        // configuration belongs to the Sources module (5) reading its own
        // `config` JSON, this repository only exposes the raw signal.
        $cutoff = EntityDates::now()->modify('-1 hour');

        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('enabled', 1))
            ->get();

        $due = array_filter($rows, static function (array $row) use ($cutoff): bool {
            if ($row['last_fetched_at'] === null) {
                return true;
            }
            return EntityDates::fromMysql((string) $row['last_fetched_at']) < $cutoff;
        });

        return array_map(fn (array $row) => $this->hydrate($row), array_values($due));
    }

    public function recordFetchResult(int $id, bool $success, ?string $error = null): void
    {
        $this->updateRow([
            'last_fetched_at' => EntityDates::toMysql(EntityDates::now()),
            'last_error'      => $success ? null : $error,
            'updated_at'      => EntityDates::toMysql(EntityDates::now()),
        ], ['id' => $id]);
    }
}
