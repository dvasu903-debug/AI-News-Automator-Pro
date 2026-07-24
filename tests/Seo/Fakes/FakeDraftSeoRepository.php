<?php
/**
 * Shared test double for DraftSeoRepositoryInterface, used by Module 9
 * (SEO) tests.
 *
 * @package AINewsAutomator\Tests\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Fakes;

use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Publishing\DTO\DraftSeo;

final class FakeDraftSeoRepository implements DraftSeoRepositoryInterface
{
    /** @var array<int, DraftSeo> */
    private array $byPostId = [];

    public function seed(DraftSeo $seo): void
    {
        $this->byPostId[$seo->postId] = $seo;
    }

    public function upsert(DraftSeo $seo): DraftSeo
    {
        $this->byPostId[$seo->postId] = $seo;

        return $seo;
    }

    public function findByPostId(int $postId): ?DraftSeo
    {
        return $this->byPostId[$postId] ?? null;
    }
}
