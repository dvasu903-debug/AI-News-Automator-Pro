<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Storage\Contracts\AiRequestRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\AiRequestRecord;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;
use AINewsAutomator\Storage\Query\SortOrder;

/**
 * @extends AbstractRepository<AiRequestRecord>
 */
final class AiRequestRepository extends AbstractRepository implements AiRequestRepositoryInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return Tables::AI_REQUESTS;
    }

    protected function hydrate(array $row): AiRequestRecord
    {
        return AiRequestRecord::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var AiRequestRecord $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var AiRequestRecord $entity */
        $errors = [];

        if (trim($entity->provider) === '') {
            $errors['provider'] = 'Provider is required.';
        }

        if (!in_array($entity->status, ['success', 'error'], true)) {
            $errors['status'] = 'Status must be "success" or "error".';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'AI request record failed validation.');
        }
    }

    public function record(AiRequestRecord $request): int
    {
        return $this->insertRow($request);
    }

    public function costSince(string $provider, \DateTimeImmutable $since): int
    {
        $table = $this->connection->table($this->table());

        $total = $this->connection->scalar(
            "SELECT SUM(cost_cents) FROM `{$table}` WHERE provider = %s AND created_at >= %s",
            [$provider, $since->format('Y-m-d H:i:s')]
        );

        return $total !== null ? (int) $total : 0;
    }

    public function paginate(int $page = 1, int $perPage = 25, ?string $provider = null, ?string $correlationId = null): PageResult
    {
        $filters = [];
        if ($provider !== null) {
            $filters[] = Filter::equals('provider', $provider);
        }
        if ($correlationId !== null) {
            $filters[] = Filter::equals('correlation_id', $correlationId);
        }

        return $this->paginateRows($filters, [SortOrder::desc('id')], $page, $perPage);
    }
}
