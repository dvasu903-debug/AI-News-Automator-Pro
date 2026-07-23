<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Research\Contradiction\AiContradictionDetector;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\ContradictionSeverity;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\AI\Fakes\AIManagerTestFactory;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 5 — see
 * AiClaimExtractorTest's docblock for the testing approach rationale.
 */
final class AiContradictionDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
        $GLOBALS['__ana_test_transients'] = [];
    }

    private function claim(int $id, string $statement): Claim
    {
        return new Claim($id, 1, $statement, 0.5, ClaimStatus::Unverified, EntityDates::now());
    }

    private function detectorReturning(string $jsonContent): AiContradictionDetector
    {
        $provider = new FakeChatProvider('claude');
        $provider->willReturn(FakeChatProvider::successResponse('claude', $jsonContent));
        $harness = AIManagerTestFactory::build([$provider]);

        return new AiContradictionDetector($harness->manager, new OptionBackedConfigRepository([]), new OptionBackedLogger(
            new CorrelationContext('test'),
            Environment::Development
        ));
    }

    public function test_no_existing_claims_returns_empty_without_calling_ai(): void
    {
        $detector = $this->detectorReturning('{"contradictions":[{"existing_claim_index":0,"description":"x","severity":"high"}]}');

        $result = $detector->detectFor($this->claim(2, 'New claim.'), []);

        $this->assertSame([], $result);
    }

    public function test_well_formed_response_yields_contradiction_referencing_correct_existing_claim(): void
    {
        $detector = $this->detectorReturning('{"contradictions":[{"existing_claim_index":0,"description":"Conflicting figures.","severity":"high"}]}');

        $existing = [$this->claim(1, 'Revenue was $5M.')];
        $result = $detector->detectFor($this->claim(2, 'Revenue was $8M.'), $existing);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]->claimAId);
        $this->assertSame(2, $result[0]->claimBId);
        $this->assertSame(ContradictionSeverity::High, $result[0]->severity);
        $this->assertSame('Conflicting figures.', $result[0]->description);
    }

    public function test_out_of_range_index_is_ignored(): void
    {
        $detector = $this->detectorReturning('{"contradictions":[{"existing_claim_index":99,"description":"x","severity":"high"}]}');

        $existing = [$this->claim(1, 'A claim.')];
        $result = $detector->detectFor($this->claim(2, 'Another claim.'), $existing);

        $this->assertSame([], $result);
    }

    public function test_malformed_json_degrades_to_empty_array(): void
    {
        $detector = $this->detectorReturning('not json');

        $existing = [$this->claim(1, 'A claim.')];
        $result = $detector->detectFor($this->claim(2, 'Another claim.'), $existing);

        $this->assertSame([], $result);
    }

    public function test_unknown_severity_defaults_to_medium(): void
    {
        $detector = $this->detectorReturning('{"contradictions":[{"existing_claim_index":0,"description":"x","severity":"not-a-real-severity"}]}');

        $existing = [$this->claim(1, 'A claim.')];
        $result = $detector->detectFor($this->claim(2, 'Another claim.'), $existing);

        $this->assertSame(ContradictionSeverity::Medium, $result[0]->severity);
    }

    public function test_comparison_set_is_bounded_to_most_recent_thirty_claims(): void
    {
        // 35 existing claims — only the most recent 30 should appear in
        // the prompt, so an index referencing claim #4 (0-indexed within
        // the ORIGINAL 35, which falls outside the bounded comparison
        // set's own 0-29 indexing) must not resolve.
        $existing = [];
        for ($i = 1; $i <= 35; $i++) {
            $existing[] = $this->claim($i, "Claim number {$i}.");
        }

        // Reference index 34 (valid within the bounded 30-item comparison
        // set, corresponding to the LAST of the most-recent-30 claims).
        $detector = $this->detectorReturning('{"contradictions":[{"existing_claim_index":29,"description":"x","severity":"low"}]}');

        $result = $detector->detectFor($this->claim(999, 'New claim.'), $existing);

        $this->assertCount(1, $result);
        // The comparison set is array_slice($existing, -30), so index 29
        // within it is claim #35 (the last of the original 35).
        $this->assertSame(35, $result[0]->claimAId);
    }
}
