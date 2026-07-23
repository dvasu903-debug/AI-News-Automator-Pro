<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Research\Entities\Evidence;
use AINewsAutomator\Research\Extraction\AiEntityExtractor;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\AI\Fakes\AIManagerTestFactory;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 5 — see
 * AiClaimExtractorTest's docblock for the testing approach rationale.
 */
final class AiEntityExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
        $GLOBALS['__ana_test_transients'] = [];
    }

    private function evidence(?string $snippet = 'Acme Corp announced a merger with Widget Inc.'): Evidence
    {
        return new Evidence(1, 1, 'https://x.test/a', 'rss', 'x.test', null, $snippet, null, EntityDates::now());
    }

    private function extractorReturning(string $jsonContent): AiEntityExtractor
    {
        $provider = new FakeChatProvider('claude');
        $provider->willReturn(FakeChatProvider::successResponse('claude', $jsonContent));
        $harness = AIManagerTestFactory::build([$provider]);

        return new AiEntityExtractor($harness->manager, new OptionBackedConfigRepository([]), new OptionBackedLogger(
            new CorrelationContext('test'),
            Environment::Development
        ));
    }

    public function test_well_formed_response_yields_entities(): void
    {
        $extractor = $this->extractorReturning('{"entities":[{"name":"Acme Corp","type":"organization"}]}');

        $result = $extractor->extract($this->evidence());

        $this->assertCount(1, $result);
        $this->assertSame('Acme Corp', $result[0]->name);
        $this->assertSame('organization', $result[0]->entityType);
    }

    public function test_malformed_json_degrades_to_empty_array(): void
    {
        $extractor = $this->extractorReturning('{not json');

        $this->assertSame([], $extractor->extract($this->evidence()));
    }

    public function test_missing_entities_key_degrades_to_empty_array(): void
    {
        $extractor = $this->extractorReturning('{}');

        $this->assertSame([], $extractor->extract($this->evidence()));
    }

    public function test_entity_with_empty_name_is_filtered_out(): void
    {
        $extractor = $this->extractorReturning('{"entities":[{"name":"","type":"person"},{"name":"Valid","type":"person"}]}');

        $result = $extractor->extract($this->evidence());

        $this->assertCount(1, $result);
        $this->assertSame('Valid', $result[0]->name);
    }

    public function test_entity_with_invalid_type_is_filtered_out(): void
    {
        $extractor = $this->extractorReturning('{"entities":[{"name":"Something","type":"not-a-real-type"}]}');

        $this->assertSame([], $extractor->extract($this->evidence()));
    }

    public function test_all_four_valid_entity_types_are_accepted(): void
    {
        $extractor = $this->extractorReturning('{"entities":[
            {"name":"A","type":"person"},
            {"name":"B","type":"organization"},
            {"name":"C","type":"place"},
            {"name":"D","type":"event"}
        ]}');

        $result = $extractor->extract($this->evidence());

        $this->assertCount(4, $result);
    }

    public function test_empty_snippet_short_circuits_without_calling_ai(): void
    {
        $extractor = $this->extractorReturning('{"entities":[{"name":"unused","type":"person"}]}');

        $this->assertSame([], $extractor->extract($this->evidence(snippet: '')));
    }
}
