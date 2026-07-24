<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\DTO;

/**
 * The outcome of any PublisherInterface operation. A single class with
 * named constructors, mirroring Workflow\DTO\ActionResult's shape
 * (see that class's docblock for the rationale) — the caller branches
 * on outcome() once rather than catching different exception types for
 * expected, non-exceptional outcomes like a policy rejection.
 */
final class PublishResult
{
    private function __construct(
        public readonly PublishOutcome $outcome,
        public readonly int $postId,
        public readonly ?\DateTimeImmutable $at,
        /** @var list<string> */
        public readonly array $reasons,
        public readonly ?string $error,
    ) {
    }

    public static function published(int $postId, \DateTimeImmutable $at): self
    {
        return new self(PublishOutcome::Published, $postId, $at, [], null);
    }

    public static function scheduled(int $postId, \DateTimeImmutable $at): self
    {
        return new self(PublishOutcome::Scheduled, $postId, $at, [], null);
    }

    public static function unpublished(int $postId): self
    {
        return new self(PublishOutcome::Unpublished, $postId, null, [], null);
    }

    public static function archived(int $postId): self
    {
        return new self(PublishOutcome::Archived, $postId, null, [], null);
    }

    /**
     * @param list<string> $reasons Policy violations or an approval-mode gate — an
     *                              expected, non-exceptional outcome, not a system error.
     */
    public static function rejected(int $postId, array $reasons): self
    {
        return new self(PublishOutcome::Rejected, $postId, null, $reasons, null);
    }

    public static function failed(int $postId, string $error): self
    {
        return new self(PublishOutcome::Failed, $postId, null, [], $error);
    }

    public function isSuccess(): bool
    {
        return in_array($this->outcome, [
            PublishOutcome::Published,
            PublishOutcome::Scheduled,
            PublishOutcome::Unpublished,
            PublishOutcome::Archived,
        ], true);
    }

    public function isRejected(): bool
    {
        return $this->outcome === PublishOutcome::Rejected;
    }

    public function isFailed(): bool
    {
        return $this->outcome === PublishOutcome::Failed;
    }
}
