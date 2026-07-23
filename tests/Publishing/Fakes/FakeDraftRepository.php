<?php
/**
 * Shared test double for DraftRepositoryInterface, used by
 * DefaultEditorialPolicyTest and PublishingServiceTest.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\Publishing\Contracts\DraftRepositoryInterface;

final class FakeDraftRepository implements DraftRepositoryInterface
{
    public bool $isGeneratedReturn = false;

    public function create(string $title, string $content, array $meta = []): int
    {
        return 1;
    }

    public function update(int $postId, ?string $title = null, ?string $content = null, array $meta = []): void
    {
    }

    public function delete(int $postId): void
    {
    }

    public function findBySourceUrl(string $url): ?int
    {
        return null;
    }

    public function isGenerated(int $postId): bool
    {
        return $this->isGeneratedReturn;
    }
}
