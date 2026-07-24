<?php
/**
 * Covers InternalLinkSuggester: deterministic shared-entity ranking,
 * published-only filtering, and no AI dependency anywhere in the class.
 *
 * @package AINewsAutomator\Tests\Seo\Services
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Services;

use AINewsAutomator\Research\DTO\DiversityReport;
use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\ExtractedEntity;
use AINewsAutomator\Seo\Services\InternalLinkSuggester;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\Seo\Fakes\FakeMultiSessionRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class InternalLinkSuggesterTest extends TestCase
{
    private FakeMultiSessionRepository $sessions;
    private InternalLinkSuggester $suggester;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_posts'] = [];
        $GLOBALS['__ana_test_postmeta'] = [];

        $this->sessions = new FakeMultiSessionRepository();
        $this->suggester = new InternalLinkSuggester($this->sessions);
    }

    /**
     * @param list<string> $entityNames
     */
    private function summary(int $sessionId, array $entityNames): ResearchSummary
    {
        $now = EntityDates::now();
        $entities = array_map(
            static fn (string $name): ExtractedEntity => new ExtractedEntity(null, $sessionId, $name, 'organization', 1, $now),
            $entityNames
        );

        return new ResearchSummary(
            sessionId: $sessionId,
            correlationId: 'corr-' . $sessionId,
            topic: 'Topic',
            topicCluster: null,
            claims: [],
            entities: $entities,
            unresolvedContradictions: [],
            sourceDiversity: new DiversityReport(0, 0, 0, 0.0),
            timeline: [],
            overallConfidence: 0.9,
            generatedAt: $now,
        );
    }

    public function test_returns_empty_when_source_post_has_no_linked_session(): void
    {
        $this->assertSame([], $this->suggester->suggestFor(1));
    }

    public function test_returns_empty_when_linked_session_has_no_entities(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 10;
        $this->sessions->seed(10, $this->summary(10, []));

        $this->assertSame([], $this->suggester->suggestFor(1));
    }

    public function test_suggests_published_post_sharing_an_entity(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 10;
        $this->sessions->seed(10, $this->summary(10, ['OpenAI']));

        $GLOBALS['__ana_test_posts'][2] = ['post_title' => 'Related Post', 'post_status' => 'publish'];
        $GLOBALS['__ana_test_postmeta'][2]['_ana_research_session_id'] = 20;
        $this->sessions->seed(20, $this->summary(20, ['OpenAI']));

        $result = $this->suggester->suggestFor(1);

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['postId']);
        $this->assertSame('Related Post', $result[0]['title']);
        $this->assertSame(1, $result[0]['sharedEntityCount']);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 10;
        $this->sessions->seed(10, $this->summary(10, ['OpenAI']));

        $GLOBALS['__ana_test_posts'][2] = ['post_title' => 'Related', 'post_status' => 'publish'];
        $GLOBALS['__ana_test_postmeta'][2]['_ana_research_session_id'] = 20;
        $this->sessions->seed(20, $this->summary(20, ['openai']));

        $this->assertCount(1, $this->suggester->suggestFor(1));
    }

    public function test_excludes_non_published_posts(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 10;
        $this->sessions->seed(10, $this->summary(10, ['OpenAI']));

        $GLOBALS['__ana_test_posts'][2] = ['post_title' => 'Draft Post', 'post_status' => 'draft'];
        $GLOBALS['__ana_test_postmeta'][2]['_ana_research_session_id'] = 20;
        $this->sessions->seed(20, $this->summary(20, ['OpenAI']));

        $this->assertSame([], $this->suggester->suggestFor(1));
    }

    public function test_excludes_posts_sharing_the_same_session(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 10;
        $this->sessions->seed(10, $this->summary(10, ['OpenAI']));

        $GLOBALS['__ana_test_posts'][2] = ['post_title' => 'Same Session', 'post_status' => 'publish'];
        $GLOBALS['__ana_test_postmeta'][2]['_ana_research_session_id'] = 10;

        $this->assertSame([], $this->suggester->suggestFor(1));
    }

    public function test_excludes_posts_sharing_no_entities(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 10;
        $this->sessions->seed(10, $this->summary(10, ['OpenAI']));

        $GLOBALS['__ana_test_posts'][2] = ['post_title' => 'Unrelated', 'post_status' => 'publish'];
        $GLOBALS['__ana_test_postmeta'][2]['_ana_research_session_id'] = 20;
        $this->sessions->seed(20, $this->summary(20, ['SpaceX']));

        $this->assertSame([], $this->suggester->suggestFor(1));
    }

    public function test_ranks_by_shared_entity_count_descending(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 10;
        $this->sessions->seed(10, $this->summary(10, ['A', 'B', 'C']));

        $GLOBALS['__ana_test_posts'][2] = ['post_title' => 'One Shared', 'post_status' => 'publish'];
        $GLOBALS['__ana_test_postmeta'][2]['_ana_research_session_id'] = 20;
        $this->sessions->seed(20, $this->summary(20, ['A']));

        $GLOBALS['__ana_test_posts'][3] = ['post_title' => 'Two Shared', 'post_status' => 'publish'];
        $GLOBALS['__ana_test_postmeta'][3]['_ana_research_session_id'] = 30;
        $this->sessions->seed(30, $this->summary(30, ['A', 'B']));

        $result = $this->suggester->suggestFor(1);

        $this->assertCount(2, $result);
        $this->assertSame(3, $result[0]['postId']);
        $this->assertSame(2, $result[0]['sharedEntityCount']);
        $this->assertSame(2, $result[1]['postId']);
        $this->assertSame(1, $result[1]['sharedEntityCount']);
    }

    public function test_respects_limit(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 10;
        $this->sessions->seed(10, $this->summary(10, ['A']));

        for ($i = 2; $i <= 5; $i++) {
            $GLOBALS['__ana_test_posts'][$i] = ['post_title' => "Post $i", 'post_status' => 'publish'];
            $GLOBALS['__ana_test_postmeta'][$i]['_ana_research_session_id'] = $i * 10;
            $this->sessions->seed($i * 10, $this->summary($i * 10, ['A']));
        }

        $this->assertCount(2, $this->suggester->suggestFor(1, 2));
    }

    public function test_never_depends_on_an_ai_manager(): void
    {
        $constructor = (new ReflectionClass(InternalLinkSuggester::class))->getConstructor();
        $this->assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $this->assertStringNotContainsStringIgnoringCase('AI\\Manager', $typeName);
        }
    }
}
