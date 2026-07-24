<?php
/**
 * Shared test double for ContentGeneratorInterface, used by
 * GenerateActionTest.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\Publishing\Contracts\ContentGeneratorInterface;
use AINewsAutomator\Publishing\DTO\GeneratedContent;
use AINewsAutomator\Research\DTO\ResearchSummary;

final class FakeContentGenerator implements ContentGeneratorInterface
{
    public ?GeneratedContent $generateReturn = null;
    public ?\Throwable $generateThrows = null;

    /** @var list<ResearchSummary> */
    public array $generateCalls = [];

    public function generate(ResearchSummary $summary): GeneratedContent
    {
        $this->generateCalls[] = $summary;

        if (null !== $this->generateThrows) {
            throw $this->generateThrows;
        }

        return $this->generateReturn ?? new GeneratedContent('Generated Title', 'Generated body.');
    }
}
