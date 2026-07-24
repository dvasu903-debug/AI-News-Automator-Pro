<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Diversity\SourceDiversityAnalyzer;
use AINewsAutomator\Research\Entities\Evidence;
use AINewsAutomator\Storage\Entities\EntityDates;
use PHPUnit\Framework\TestCase;

final class SourceDiversityAnalyzerTest extends TestCase
{
    private function evidence(string $domain, string $sourceType = 'rss'): Evidence
    {
        return new Evidence(null, 1, 'https://' . $domain . '/article', $sourceType, $domain, null, null, null, EntityDates::now());
    }

    public function test_empty_evidence_scores_zero(): void
    {
        $report = (new SourceDiversityAnalyzer())->analyze([]);

        $this->assertSame(0, $report->totalEvidence);
        $this->assertSame(0.0, $report->diversityScore);
    }

    public function test_single_domain_has_low_diversity(): void
    {
        $report = (new SourceDiversityAnalyzer())->analyze([
            $this->evidence('example.test'),
            $this->evidence('example.test'),
            $this->evidence('example.test'),
        ]);

        $this->assertSame(1, $report->distinctDomains);
        $this->assertSame(3, $report->totalEvidence);
        $this->assertLessThan(0.5, $report->diversityScore);
    }

    public function test_five_distinct_domains_reaches_full_diversity(): void
    {
        $report = (new SourceDiversityAnalyzer())->analyze([
            $this->evidence('a.test'),
            $this->evidence('b.test'),
            $this->evidence('c.test'),
            $this->evidence('d.test'),
            $this->evidence('e.test'),
        ]);

        $this->assertSame(5, $report->distinctDomains);
        $this->assertSame(1.0, $report->diversityScore);
    }

    public function test_diversity_score_never_exceeds_one(): void
    {
        $evidence = [];
        for ($i = 0; $i < 20; $i++) {
            $evidence[] = $this->evidence("domain{$i}.test");
        }

        $report = (new SourceDiversityAnalyzer())->analyze($evidence);

        $this->assertLessThanOrEqual(1.0, $report->diversityScore);
    }

    public function test_distinct_source_types_counted_separately_from_domains(): void
    {
        $report = (new SourceDiversityAnalyzer())->analyze([
            $this->evidence('a.test', 'rss'),
            $this->evidence('b.test', 'web_crawler'),
        ]);

        $this->assertSame(2, $report->distinctSourceTypes);
        $this->assertSame(2, $report->distinctDomains);
    }
}
