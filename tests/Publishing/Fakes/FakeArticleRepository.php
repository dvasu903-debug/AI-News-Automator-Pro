<?php
/**
 * Shared test double for ArticleRepositoryInterface, used by
 * PublishingServiceTest and Action tests.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;

final class FakeArticleRepository implements ArticleRepositoryInterface
{
    public int $nextId = 1;
    public ?int $bySourceUrlReturn = null;
    public bool $isGeneratedReturn = false;
    public bool $approveReturn = true;

    /** @var list<int> */
    public array $approveCalls = [];

    public function createDraft(string $title, string $content, array $meta = []): int
    {
        return $this->nextId++;
    }

    public function approve(int $postId): bool
    {
        $this->approveCalls[] = $postId;

        return $this->approveReturn;
    }

    public function pendingReview(): array
    {
        return [];
    }

    public function bySourceUrl(string $url): ?int
    {
        return $this->bySourceUrlReturn;
    }

    public function isGenerated(int $postId): bool
    {
        return $this->isGeneratedReturn;
    }
}
