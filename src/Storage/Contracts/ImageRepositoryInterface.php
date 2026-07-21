<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

use AINewsAutomator\Storage\Entities\ImageRecord;

interface ImageRepositoryInterface
{
    public function record(ImageRecord $image): int;

    /**
     * @return list<ImageRecord>
     */
    public function forArticle(int $articleId): array;

    /**
     * @return list<ImageRecord> Rows referencing an attachment/article id
     *                          that no longer exists in wp_posts.
     */
    public function findOrphans(): array;
}
