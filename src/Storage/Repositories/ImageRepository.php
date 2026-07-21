<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\ImageRepositoryInterface;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\ImageRecord;
use AINewsAutomator\Storage\Events\ImageRecordedEvent;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;

/**
 * @extends AbstractRepository<ImageRecord>
 */
final class ImageRepository extends AbstractRepository implements ImageRepositoryInterface
{
    private const VALID_SOURCES = ['unsplash', 'ai_generated', 'manual'];

    public function __construct(
        ConnectionInterface $connection,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return Tables::IMAGES;
    }

    protected function hydrate(array $row): ImageRecord
    {
        return ImageRecord::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var ImageRecord $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var ImageRecord $entity */
        if (!in_array($entity->source, self::VALID_SOURCES, true)) {
            throw new ValidationException(
                ['source' => 'Unrecognized image source: ' . $entity->source],
                'Image record failed validation.'
            );
        }
    }

    public function record(ImageRecord $image): int
    {
        $id = $this->insertRow($image);

        $this->events->dispatch(new ImageRecordedEvent(
            $this->metadataFactory->create('Storage', ['image_id' => $id]),
            imageId: $id,
            articleId: $image->articleId,
            source: $image->source,
        ));

        return $id;
    }

    public function forArticle(int $articleId): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('article_id', $articleId))
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function findOrphans(): array
    {
        // "Orphan" = references a wp_posts id that no longer exists. No
        // formal FK (WordPress convention, see module README), so this is
        // an explicit anti-join rather than relying on constraint violations.
        global $wpdb;

        $table = $this->connection->table($this->table());
        $postsTable = $wpdb->posts;

        $rows = $this->connection->select(
            "SELECT img.* FROM `{$table}` img
             LEFT JOIN `{$postsTable}` p_article ON p_article.ID = img.article_id
             LEFT JOIN `{$postsTable}` p_attachment ON p_attachment.ID = img.attachment_id
             WHERE (img.article_id IS NOT NULL AND p_article.ID IS NULL)
                OR (img.attachment_id IS NOT NULL AND p_attachment.ID IS NULL)"
        );

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}
