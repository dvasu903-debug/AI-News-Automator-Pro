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

/**
 * Processes one "source.fetch" queue job: resolves the source's connector
 * via the registry (never instantiated directly), fetches through
 * SourceRetryExecutor, then runs the shared validate/dedup/event
 * pipeline. Used for every connector type except web_crawler, which is
 * queued per-seed-URL via CrawlUrlJobHandler instead.
 */
final class FetchSourceJobHandler extends AbstractSourceJobHandler
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
     * @param array<string, mixed> $payload {"source_id": int}
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $source = $this->sources->find($sourceId);

        if ($source === null) {
            $this->logger->warning('source.fetch job referenced unknown source id {id}.', ['id' => $sourceId]);
            return ['skipped' => true];
        }

        $connector = $this->registry->forType($source->type);

        if ($connector === null) {
            $this->sources->recordFetchResult($sourceId, false, sprintf('No connector registered for type "%s".', $source->type));
            return ['skipped' => true];
        }

        $this->events->dispatch(new SourceFetchStartedEvent(
            $this->metadataFactory->create('Sources', ['source_id' => $sourceId]),
            sourceId: $sourceId,
            type: $source->type,
        ));

        $start = microtime(true);

        try {
            $result = $this->retry->execute((string) $sourceId, static fn () => $connector->fetch($source));
        } catch (SourceFetchException $e) {
            $this->recordFailure($sourceId, $e->getMessage());
            // Rethrown so the queue's own retry/history machinery
            // (QueueRepository::markFailure(), separate from this
            // module's HTTP-level retry) records the job outcome.
            throw $e;
        }

        $durationMs = (microtime(true) - $start) * 1000;

        if ($result->status === FetchStatus::Failed) {
            $this->recordFailure($sourceId, $result->errorMessage ?? 'Unknown fetch failure.');
            return ['success' => false, 'error' => $result->errorMessage];
        }

        $counts = $this->processItems($sourceId, $result);

        $this->sources->recordFetchResult($sourceId, true);
        $this->metrics->increment('source.fetch_success', 1, ['source_id' => $sourceId]);

        $this->events->dispatch(new SourceFetchCompletedEvent(
            $this->metadataFactory->create('Sources', ['source_id' => $sourceId]),
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
