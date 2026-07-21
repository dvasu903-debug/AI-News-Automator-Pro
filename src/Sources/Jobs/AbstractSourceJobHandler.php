<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Jobs;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Sources\Contracts\DeduplicationInterface;
use AINewsAutomator\Sources\Contracts\SourceValidatorInterface;
use AINewsAutomator\Sources\Dedup\SourceItemStatus;
use AINewsAutomator\Sources\DTO\FetchResult;
use AINewsAutomator\Sources\Events\DuplicateItemSkippedEvent;
use AINewsAutomator\Sources\Events\ItemDiscoveredEvent;
use AINewsAutomator\Sources\Exceptions\SourceValidationException;

/**
 * Shared item-processing pipeline (validate -> dedup -> mark seen ->
 * emit event) used by both FetchSourceJobHandler and CrawlUrlJobHandler
 * — extracted here so the two handlers don't duplicate this logic.
 */
abstract class AbstractSourceJobHandler
{
    public function __construct(
        protected readonly SourceValidatorInterface $validator,
        protected readonly DeduplicationInterface $dedup,
        protected readonly EventDispatcherInterface $events,
        protected readonly EventMetadataFactory $metadataFactory,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{discovered: int, duplicate: int, rejected: int}
     */
    protected function processItems(int $sourceId, FetchResult $result): array
    {
        $discovered = 0;
        $duplicate = 0;
        $rejected = 0;

        foreach ($result->items as $item) {
            try {
                $this->validator->validateItem($item);
            } catch (SourceValidationException $e) {
                $this->dedup->markSeen($sourceId, $item, SourceItemStatus::Rejected->value);
                $this->logger->info('Rejected discovered item from source {source}: {reason}', [
                    'source' => $sourceId,
                    'reason' => $e->getMessage(),
                ]);
                $rejected++;
                continue;
            }

            if ($this->dedup->isDuplicate($sourceId, $item)) {
                $this->dedup->markSeen($sourceId, $item, SourceItemStatus::Seen->value);
                $duplicate++;

                $this->events->dispatch(new DuplicateItemSkippedEvent(
                    $this->metadataFactory->create('Sources', ['source_id' => $sourceId]),
                    sourceId: $sourceId,
                    fingerprint: $item->fingerprint(),
                    url: $item->url,
                ));

                continue;
            }

            $this->dedup->markSeen($sourceId, $item, SourceItemStatus::Seen->value);
            $discovered++;

            $this->events->dispatch(new ItemDiscoveredEvent(
                $this->metadataFactory->create('Sources', ['source_id' => $sourceId]),
                sourceId: $sourceId,
                fingerprint: $item->fingerprint(),
                url: $item->url,
                title: $item->title,
            ));
        }

        return ['discovered' => $discovered, 'duplicate' => $duplicate, 'rejected' => $rejected];
    }
}
