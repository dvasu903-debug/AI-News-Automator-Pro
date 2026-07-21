<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Repositories;

use AINewsAutomator\Research\Contracts\ClaimRepositoryInterface;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimEvidenceLink;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\EvidenceRelationship;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;

/**
 * @extends AbstractRepository<Claim>
 */
final class ClaimRepository extends AbstractRepository implements ClaimRepositoryInterface
{
    private const LINK_TABLE = 'research_claim_evidence';

    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return 'research_claims';
    }

    protected function hydrate(array $row): Claim
    {
        return Claim::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var Claim $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var Claim $entity */
        $errors = [];

        if ($entity->sessionId <= 0) {
            $errors['session_id'] = 'A valid session id is required.';
        }

        if (trim($entity->statement) === '') {
            $errors['statement'] = 'Claim statement is required.';
        }

        if ($entity->confidenceScore < 0.0 || $entity->confidenceScore > 1.0) {
            $errors['confidence_score'] = 'Confidence score must be between 0 and 1.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Claim failed validation.');
        }
    }

    public function record(Claim $claim): int
    {
        return $this->insertRow($claim);
    }

    public function updateStatusAndConfidence(int $claimId, ClaimStatus $status, float $confidence): void
    {
        $this->updateRow(
            ['status' => $status->value, 'confidence_score' => $confidence],
            ['id' => $claimId]
        );
    }

    public function forSession(int $sessionId): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('session_id', $sessionId))
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function find(int $id): ?Claim
    {
        return $this->findRow($id);
    }

    public function linkEvidence(int $claimId, int $evidenceId, EvidenceRelationship $relationship): int
    {
        $link = new ClaimEvidenceLink(null, $claimId, $evidenceId, $relationship, EntityDates::now());

        return $this->connection->insert(self::LINK_TABLE, $link->toRow());
    }

    public function evidenceLinksFor(int $claimId): array
    {
        $rows = $this->connection->newQuery(self::LINK_TABLE)
            ->where(Filter::equals('claim_id', $claimId))
            ->get();

        return array_map(static fn (array $row) => ClaimEvidenceLink::fromRow($row), $rows);
    }
}
