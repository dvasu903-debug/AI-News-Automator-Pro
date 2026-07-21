<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Jobs;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Sources\Contracts\DeduplicationInterface;
use AINewsAutomator\Sources\Contracts\SourceConnectorRegistryInterface;
use AINewsAutomator\Sources\Contracts\SourceValidatorInterface;
use AINewsAutomator\Sources\DTO\FetchStatus;
use AINewsAutomator\Sources\Events\SourceFetchCompletedEvent;
use AINewsAutomator\Sources\Events\SourceFetchFailedEvent;
use AINewsAutomator\Sources\Events\SourceFetchStartedEvent;
use AINewsAutomator\Sources\Exceptions\SourceFetchException;
use AINewsAutomator\Sources\Retry\SourceRetryExecutor;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;
use AINewsAutomator\Storage\Entities\SourceRecord;

/**
 * Processes one "source.crawl" queue job: a single seed URL belonging to
 * a web_crawler-typed source. A source configured with multiple seed
 * URLs is queued as one job per URL by SourceSyncScheduler, rather than
 * one job crawling everything — keeping each queued unit of work small
 * and independently retryable.
 */
final class CrawlUrlJobHandler extends AbstractSourceJobHandler
{
    public function __construct(
        SourceValidatorInterface $validator,
        DeduplicationInterface $dedup,
        EventDispatcherInterface $events,
        EventMetadataFactory $metadataFactory,
        LoggerInterface $logger,
        private readonly SourceConnectorRegistryInterface $registry,
        private readonly SourceRepositoryInterface $sources,
        private readonly SourceRetryExecutor $retry,
        private readonly MetricsRepositoryInterface $metrics,
    ) {
        parent::__construct($validator, $dedup, $events, $metadataFactory, $logger);
    }

    /**
     * @param array<string, mixed> $payload {"source_id": int, "seed_url": string}
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $seedUrl = (string) ($payload['seed_url'] ?? '');

        $source = $this->sources->find($sourceId);

        if ($source === null || $seedUrl === '') {
            $this->logger->warning('source.crawl job had an invalid payload (source {id}, url "{url}").', [
                'id'  => $sourceId,
                'url' => $seedUrl,
            ]);
            return ['skipped' => true];
        }

        $connector = $this->registry->forType('web_crawler');

        if ($connector === null) {
            $this->recordFailure($sourceId, 'No web_crawler connector registered.');
            return ['skipped' => true];
        }

        // Build a per-job SourceRecord with this specific seed URL — the
        // connector reads config['seed_url'], so each queued URL gets its
        // own effective config without mutating the stored source.
        $jobSource = new SourceRecord(
            id: $source->id,
            name: $source->name,
            type: $source->type,
            config: array_merge($source->config, ['seed_url' => $seedUrl]),
            enabled: $source->enabled,
            lastFetchedAt: $source->lastFetchedAt,
            lastError: $source->lastError,
            createdAt: $source->createdAt,
            updatedAt: $source->updatedAt,
        );

        $this->events->dispatch(new SourceFetchStartedEvent(
            $this->metadataFactory->create('Sources', ['source_id' => $sourceId, 'seed_url' => $seedUrl]),
            sourceId: $sourceId,
            type: 'web_crawler',
        ));

        $start = microtime(true);

        try {
            $result = $this->retry->execute($sourceId . ':' . $seedUrl, static fn () => $connector->fetch($jobSource));
        } catch (SourceFetchException $e) {
            $this->recordFailure($sourceId, $e->getMessage());
            throw $e;
        }

        $durationMs = (microtime(true) - $start) * 1000;

        if ($result->status === FetchStatus::Failed) {
            $this->recordFailure($sourceId, $result->errorMessage ?? 'Unknown crawl failure.');
            return ['success' => false, 'error' => $result->errorMessage];
        }

        $counts = $this->processItems($sourceId, $result);

        $this->sources->recordFetchResult($sourceId, true);
        $this->metrics->increment('source.fetch_success', 1, ['source_id' => $sourceId]);

        $this->events->dispatch(new SourceFetchCompletedEvent(
            $this->metadataFactory->create('Sources', ['source_id' => $sourceId, 'seed_url' => $seedUrl]),
            sourceId: $sourceId,
            itemsDiscovered: $counts['discovered'],
            itemsDuplicate: $counts['duplicate'],
            durationMs: $durationMs,
        ));

        return [
            'success'    => true,
            'discovered' => $counts['discovered'],
            'duplicate'  => $counts['duplicate'],
            'rejected'   => $counts['rejected'],
        ];
    }

    private function recordFailure(int $sourceId, string $message): void
    {
        $this->sources->recordFetchResult($sourceId, false, $message);
        $this->metrics->increment('source.fetch_failure', 1, ['source_id' => $sourceId]);

        $this->events->dispatch(new SourceFetchFailedEvent(
            $this->metadataFactory->create('Sources', ['source_id' => $sourceId]),
            sourceId: $sourceId,
            errorMessage: $message,
        ));
    }
}
