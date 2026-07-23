<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Repositories;

use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;
use DateTimeImmutable;

/**
 * Mirrors PublishingProfileRepository's AbstractRepository-based pattern
 * (Milestone 2). upsert() is a find-then-insert-or-update rather than a
 * single ON DUPLICATE KEY statement — ConnectionInterface's only atomic
 * upsert primitive (upsertIncrement()) is for counter columns, not a
 * general record replace, and this table's write volume (one row per
 * draft, from a single workflow step) has no concurrency pressure that
 * would require a single-statement atomic upsert.
 *
 * @extends AbstractRepository<DraftSeo>
 */
final class DraftSeoRepository extends AbstractRepository implements DraftSeoRepositoryInterface
{
    private const TABLE = 'draft_seo';

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function hydrate(array $row): DraftSeo
    {
        return new DraftSeo(
            id: isset($row['id']) ? (int) $row['id'] : null,
            postId: (int) $row['post_id'],
            metaTitle: isset($row['meta_title']) ? (string) $row['meta_title'] : null,
            metaDescription: isset($row['meta_description']) ? (string) $row['meta_description'] : null,
            focusKeyword: isset($row['focus_keyword']) ? (string) $row['focus_keyword'] : null,
            canonicalUrl: isset($row['canonical_url']) ? (string) $row['canonical_url'] : null,
            robotsDirectives: isset($row['robots_directives']) ? (string) $row['robots_directives'] : null,
            createdAt: isset($row['created_at']) ? EntityDates::fromMysql((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? EntityDates::fromMysql((string) $row['updated_at']) : null,
        );
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var DraftSeo $entity */
        $now = $this->now();

        return [
            'post_id'            => $entity->postId,
            'meta_title'         => $entity->metaTitle,
            'meta_description'   => $entity->metaDescription,
            'focus_keyword'      => $entity->focusKeyword,
            'canonical_url'      => $entity->canonicalUrl,
            'robots_directives'  => $entity->robotsDirectives,
            'created_at'         => $now->format('Y-m-d H:i:s'),
            'updated_at'         => $now->format('Y-m-d H:i:s'),
        ];
    }

    public function upsert(DraftSeo $seo): DraftSeo
    {
        $existing = $this->findByPostId($seo->postId);
        $now = $this->now();

        if (null === $existing) {
            $id = $this->insertRow($seo);

            return $seo->withId($id)->withTimestamps($now, $now);
        }

        $this->updateRow(
            [
                'meta_title'        => $seo->metaTitle,
                'meta_description'  => $seo->metaDescription,
                'focus_keyword'     => $seo->focusKeyword,
                'canonical_url'     => $seo->canonicalUrl,
                'robots_directives' => $seo->robotsDirectives,
                'updated_at'        => $now->format('Y-m-d H:i:s'),
            ],
            ['id' => $existing->id]
        );

        return $seo->withId((int) $existing->id)->withTimestamps($existing->createdAt ?? $now, $now);
    }

    public function findByPostId(int $postId): ?DraftSeo
    {
        $row = $this->connection->newQuery($this->table())
            ->where(Filter::equals('post_id', $postId))
            ->first();

        return null === $row ? null : $this->hydrate($row);
    }

    private function now(): DateTimeImmutable
    {
        return EntityDates::now();
    }
}
