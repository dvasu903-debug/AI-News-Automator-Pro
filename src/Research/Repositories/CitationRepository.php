<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Repositories;

use AINewsAutomator\Research\Contracts\CitationRepositoryInterface;
use AINewsAutomator\Research\Entities\Citation;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;

/**
 * @extends AbstractRepository<Citation>
 */
final class CitationRepository extends AbstractRepository implements CitationRepositoryInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return 'research_citations';
    }

    protected function hydrate(array $row): Citation
    {
        return Citation::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var Citation $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var Citation $entity */
        $errors = [];

        if ($entity->claimId <= 0) {
            $errors['claim_id'] = 'A valid claim id is required.';
        }

        if ($entity->evidenceId <= 0) {
            $errors['evidence_id'] = 'A valid evidence id is required.';
        }

        if (trim($entity->citationText) === '') {
            $errors['citation_text'] = 'Citation text is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Citation failed validation.');
        }
    }

    public function record(Citation $citation): int
    {
        return $this->insertRow($citation);
    }

    public function forClaim(int $claimId): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('claim_id', $claimId))
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function forSession(int $sessionId): array
    {
        // Citations don't carry session_id directly (they're scoped by
        // claim_id/evidence_id — both of which DO carry session_id
        // transitively). A join would be the efficient way to do this
        // against a real database; for this repository's scale (citations
        // per session are a small, bounded set), a two-step lookup via
        // the claims table keeps the query layer simple without a
        // hand-rolled join, at the cost of N+1-shaped queries bounded by
        // claim count, not citation count.
        $claimRows = $this->connection->newQuery('research_claims')
            ->select(['id'])
            ->where(Filter::equals('session_id', $sessionId))
            ->get();

        $citations = [];
        foreach ($claimRows as $claimRow) {
            foreach ($this->forClaim((int) $claimRow['id']) as $citation) {
                $citations[] = $citation;
            }
        }

        return $citations;
    }
}
