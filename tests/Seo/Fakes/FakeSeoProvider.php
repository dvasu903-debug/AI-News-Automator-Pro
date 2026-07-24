<?php
/**
 * Shared test double for SeoProviderInterface, used by
 * SeoHeadRendererTest.
 *
 * @package AINewsAutomator\Tests\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Fakes;

use AINewsAutomator\Seo\Contracts\SeoProviderInterface;
use AINewsAutomator\Seo\DTO\SeoTagData;

final class FakeSeoProvider implements SeoProviderInterface
{
    public ?SeoTagData $provideReturn = null;

    public function provide(int $postId): ?SeoTagData
    {
        return $this->provideReturn;
    }
}
