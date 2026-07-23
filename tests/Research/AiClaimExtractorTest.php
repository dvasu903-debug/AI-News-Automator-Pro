<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Research\Entities\Evidence;
use AINewsAutomator\Research\Extraction\AiClaimExtractor;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\AI\Fakes\AIManagerTestFactory;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 5: exercises the
 * REAL response-parsing logic (not a fake substitute) by wiring a real
 * AIManager against AI module's own FakeChatProvider — the response
 * content is controlled, but the JSON-decoding/validation code under
 * test is genuinely AiClaimExtractor's own.
 */
final class AiClaimExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
        $GLOBALS['__ana_test_transients'] = [];
    }

    private function evidence(?string $snippet = 'The sky is blue and grass is green.'): Evidence
    {
        return new Evidence(1, 1, 'https://x.test/a', 'rss', 'x.test', null, $snippet, null, EntityDates::now());
    }

    private function extractorReturning(string $jsonContent): AiClaimExtractor
    {
        $provider = new FakeChatProvider('claude');
        $provider->willReturn(FakeChatProvider::successResponse('claude', $jsonContent));
        $harness = AIManagerTestFactory::build([$provider]);

        return new AiClaimExtractor($harness->manager, new OptionBackedConfigRepository([]), new OptionBackedLogger(
            new CorrelationContext('test'),
            Environment::Development
        ));
    }

    public function test_well_formed_response_yields_claims(): void
    {
        $extractor = $this->extractorReturning('{"claims":[{"statement":"The sky is blue.","confidence":0.9}]}');

        $result = $extractor->extract($this->evidence());

        $this->assertCount(1, $result);
        $this->assertSame('The sky is blue.', $result[0]->statement);
        $this->assertSame(0.9, $result[0]->extractionConfidence);
    }

    public function test_multiple_claims_are_all_returned(): void
    {
        $extractor = $this->extractorReturning('{"claims":[{"statement":"A.","confidence":0.5},{"statement":"B.","confidence":0.6}]}');

        $result = $extractor->extract($this->evidence());

        $this->assertCount(2, $result);
    }

    public function test_malformed_json_degrades_to_empty_array(): void
    {
        $extractor = $this->extractorReturning('not valid json at all');

        $result = $extractor->extract($this->evidence());

        $this->assertSame([], $result);
    }

    public function test_missing_claims_key_degrades_to_empty_array(): void
    {
        $extractor = $this->extractorReturning('{"something_else": []}');

        $result = $extractor->extract($this->evidence());

        $this->assertSame([], $result);
    }

    public function test_claim_with_empty_statement_is_filtered_out(): void
    {
        $extractor = $this->extractorReturning('{"claims":[{"statement":"","confidence":0.5},{"statement":"Valid.","confidence":0.5}]}');

        $result = $extractor->extract($this->evidence());

        $this->assertCount(1, $result);
        $this->assertSame('Valid.', $result[0]->statement);
    }

    public function test_confidence_above_one_is_clamped(): void
    {
        $extractor = $this->extractorReturning('{"claims":[{"statement":"X.","confidence":5.0}]}');

        $result = $extractor->extract($this->evidence());

        $this->assertSame(1.0, $result[0]->extractionConfidence);
    }

    public function test_confidence_below_zero_is_clamped(): void
    {
        $extractor = $this->extractorReturning('{"claims":[{"statement":"X.","confidence":-3.0}]}');

        $result = $extractor->extract($this->evidence());

        $this->assertSame(0.0, $result[0]->extractionConfidence);
    }

    public function test_missing_confidence_defaults_to_half(): void
    {
        $extractor = $this->extractorReturning('{"claims":[{"statement":"X."}]}');

        $result = $extractor->extract($this->evidence());

        $this->assertSame(0.5, $result[0]->extractionConfidence);
    }

    public function test_empty_snippet_short_circuits_without_calling_ai(): void
    {
        $extractor = $this->extractorReturning('{"claims":[{"statement":"unused","confidence":0.5}]}');

        $result = $extractor->extract($this->evidence(snippet: ''));

        $this->assertSame([], $result);
    }

    public function test_null_snippet_short_circuits_without_calling_ai(): void
    {
        $extractor = $this->extractorReturning('{"claims":[{"statement":"unused","confidence":0.5}]}');

        $result = $extractor->extract($this->evidence(snippet: null));

        $this->assertSame([], $result);
    }
}
