<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * One research investigation. Groups Evidence/Claims/Entities gathered
 * toward understanding a topic. correlationId ties back to the
 * originating Sources ItemDiscoveredEvent when auto-started, or is
 * freshly generated for a manually-started session.
 */
final class ResearchSession
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $correlationId,
        public readonly string $topic,
        public readonly string $vertical,
        public readonly SessionStatus $status,
        public readonly ?string $topicCluster,
        public readonly ?float $confidenceScore,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $completedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            correlationId: (string) $row['correlation_id'],
            topic: (string) $row['topic'],
            vertical: (string) $row['vertical'],
            status: SessionStatus::from((string) $row['status']),
            topicCluster: $row['topic_cluster'] !== null ? (string) $row['topic_cluster'] : null,
            confidenceScore: isset($row['confidence_score']) && $row['confidence_score'] !== null ? (float) $row['confidence_score'] : null,
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
            updatedAt: EntityDates::fromMysql((string) $row['updated_at']),
            completedAt: EntityDates::nullableFromMysql($row['completed_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'correlation_id'   => $this->correlationId,
            'topic'            => $this->topic,
            'vertical'         => $this->vertical,
            'status'           => $this->status->value,
            'topic_cluster'    => $this->topicCluster,
            'confidence_score' => $this->confidenceScore,
            'created_at'       => EntityDates::toMysql($this->createdAt),
            'updated_at'       => EntityDates::toMysql($this->updatedAt),
            'completed_at'     => EntityDates::nullableToMysql($this->completedAt),
        ];
    }

    public function withStatus(SessionStatus $status): self
    {
        return new self(
            $this->id,
            $this->correlationId,
            $this->topic,
            $this->vertical,
            $status,
            $this->topicCluster,
            $this->confidenceScore,
            $this->createdAt,
            EntityDates::now(),
            $status === SessionStatus::Completed ? EntityDates::now() : $this->completedAt,
        );
    }

    public function withConfidenceScore(float $score): self
    {
        return new self(
            $this->id,
            $this->correlationId,
            $this->topic,
            $this->vertical,
            $this->status,
            $this->topicCluster,
            $score,
            $this->createdAt,
            EntityDates::now(),
            $this->completedAt,
        );
    }

    public function withTopicCluster(string $cluster): self
    {
        return new self(
            $this->id,
            $this->correlationId,
            $this->topic,
            $this->vertical,
            $this->status,
            $cluster,
            $this->confidenceScore,
            $this->createdAt,
            EntityDates::now(),
            $this->completedAt,
        );
    }
}
