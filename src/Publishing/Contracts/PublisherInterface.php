<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Contracts;

use AINewsAutomator\Publishing\DTO\PublishResult;

/**
 * Direct, manual publishing state transitions for an existing draft
 * post — not the automated generation pipeline (see ADR-0018). These
 * operations are plain CRUD-style calls a human editor or a Publishing
 * Action can invoke directly; they do not require a Workflow run.
 */
interface PublisherInterface
{
    public function publish(int $postId): PublishResult;

    public function schedule(int $postId, \DateTimeImmutable $at): PublishResult;

    public function unpublish(int $postId): PublishResult;

    public function archive(int $postId): PublishResult;
}
