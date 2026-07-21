<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * A row in `ana_images` — sourcing metadata for an image used on an
 * article (attribution, source type). `attachmentId`/`articleId` reference
 * wp_posts logically, with no formal FK (see module README).
 */
final class ImageRecord
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $attachmentId,
        public readonly ?int $articleId,
        public readonly string $source,
        public readonly ?string $sourceUrl,
        public readonly ?string $creditText,
        public readonly ?string $creditUrl,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            attachmentId: isset($row['attachment_id']) && $row['attachment_id'] !== null ? (int) $row['attachment_id'] : null,
            articleId: isset($row['article_id']) && $row['article_id'] !== null ? (int) $row['article_id'] : null,
            source: (string) $row['source'],
            sourceUrl: $row['source_url'] !== null ? (string) $row['source_url'] : null,
            creditText: $row['credit_text'] !== null ? (string) $row['credit_text'] : null,
            creditUrl: $row['credit_url'] !== null ? (string) $row['credit_url'] : null,
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'attachment_id' => $this->attachmentId,
            'article_id'    => $this->articleId,
            'source'        => $this->source,
            'source_url'    => $this->sourceUrl,
            'credit_text'   => $this->creditText,
            'credit_url'    => $this->creditUrl,
            'created_at'    => EntityDates::toMysql($this->createdAt),
        ];
    }
}
