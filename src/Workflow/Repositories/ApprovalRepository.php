<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Repositories;

use AINewsAutomator\Storage\Exceptions\RepositoryException;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;
use AINewsAutomator\Workflow\Contracts\ApprovalRepositoryInterface;
use AINewsAutomator\Workflow\Entities\Approval;
use AINewsAutomator\Workflow\Entities\ApprovalStatus;

/**
 * Once an Approval's decidedAt is set it is immutable — save() rejects
 * any attempt to persist changes to an id that was already decided
 * (Part 5 — "no update path on a resolved approval record"). Only a
 * fresh, still-Pending record can transition; the transition itself is
 * done by constructing a new value via Approval::decide() and passing
 * it back through save(), which then also refuses to be called again.
 *
 * @extends AbstractRepository<Approval>
 */
final class ApprovalRepository extends AbstractRepository implements ApprovalRepositoryInterface
{
    protected function table(): string
    {
        return 'workflow_approvals';
    }

    protected function hydrate(array $row): Approval
    {
        return Approval::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var Approval $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var Approval $entity */
        $errors = [];

        if (trim($entity->stepKey) === '') {
            $errors['step_key'] = 'Step key is required.';
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $errors is used to build an internal validation-exception message only, never echoed as HTML.
            throw new ValidationException($errors, 'Approval failed validation.');
        }
    }

    public function find(int $id): ?Approval
    {
        return $this->findRow($id);
    }

    public function findPendingForRunStep(int $runId, string $stepKey): ?Approval
    {
        $row = $this->connection->newQuery($this->table())
            ->whereAll([
                Filter::equals('run_id', $runId),
                Filter::equals('step_key', $stepKey),
                Filter::equals('status', ApprovalStatus::Pending->value),
            ])
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(Approval $approval): int
    {
        if ($approval->id === null) {
            return $this->insertRow($approval);
        }

        $existing = $this->findRow($approval->id);

        if ($existing === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $approval->id is used to build an internal exception message only, never echoed as HTML.
            throw RepositoryException::notFound(static::class, $approval->id);
        }

        if ($existing->status->isTerminal()) {
            throw new ValidationException(
                ['status' => 'This approval was already decided and cannot be modified — approval decisions are immutable once recorded.'],
                'Cannot update a decided approval.'
            );
        }

        $this->validate($approval);
        $this->updateRow($this->dehydrate($approval), ['id' => $approval->id]);

        return $approval->id;
    }
}
