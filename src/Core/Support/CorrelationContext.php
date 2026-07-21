<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Support;

/**
 * Holds a correlation ID for the current execution context.
 *
 * A correlation ID ties together every log entry and every event emitted
 * while handling one logical unit of work — e.g. a single pipeline run
 * that fans out across source-fetch, fact-check, write, and publish
 * steps. When something fails three stages deep, filtering logs by the
 * correlation ID reconstructs the entire causal chain for that one run,
 * instead of leaving you to guess which "fact-check failed" line belongs
 * to which run in a busy log.
 *
 * This is a single shared instance (registered in the container), not a
 * static/global — it's injected wherever needed. A long-running worker
 * that processes multiple jobs calls renew() between jobs so each job
 * gets a fresh ID.
 */
final class CorrelationContext
{
    private string $correlationId;

    public function __construct(?string $initialId = null)
    {
        $this->correlationId = $initialId ?? self::generate();
    }

    public function id(): string
    {
        return $this->correlationId;
    }

    /**
     * Starts a new correlation scope, returning the new ID. Called at the
     * top of each discrete unit of work (e.g. each queued job).
     */
    public function renew(): string
    {
        return $this->correlationId = self::generate();
    }

    /**
     * Adopts an externally-supplied correlation ID — e.g. a queued job
     * carries the ID of the request that enqueued it, so work done later
     * in the background still correlates back to its originating request.
     */
    public function adopt(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    private static function generate(): string
    {
        return Uuid::v4();
    }
}
