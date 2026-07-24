<?php
/**
 * Covers PostProcessAction: deterministic SEO metadata derivation and
 * ana_draft_seo population (approved Decision 6).
 *
 * @package AINewsAutomator\Tests\Publishing\Actions
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Actions;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Publishing\Actions\PostProcessAction;
use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Publishing\Events\PublishingCompletedEvent;
use AINewsAutomator\Publishing\Repositories\DraftSeoRepository;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;
use PHPUnit\Framework\TestCase;

final class PostProcessActionTest extends TestCase
{
    private FakeWpdb $wpdb;
    private DraftSeoRepository $seoRepository;
    private EventDispatcher $events;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_posts'] = [];

        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->wpdb->createTable('wp_ana_draft_seo');

        $this->seoRepository = new DraftSeoRepository(new Connection());
        $this->events = new EventDispatcher();
        $this->dispatched = [];

        $this->events->addListener(PublishingCompletedEvent::class, function (object $e): void {
            $this->dispatched[] = $e;
        });
    }

    private function action(): PostProcessAction
    {
        return new PostProcessAction(
            $this->seoRepository,
            $this->events,
            new EventMetadataFactory(new CorrelationContext('test'))
        );
    }

    private function context(array $stepConfig, array $priorStepOutputs = []): WorkflowRunContext
    {
        return new WorkflowRunContext(1, 'post_process', 'corr-1', $stepConfig, $priorStepOutputs);
    }

    public function test_requires_resolvable_post_id(): void
    {
        $result = $this->action()->execute($this->context([]));

        $this->assertTrue($result->isFailure());
    }

    public function test_reads_post_id_from_prior_generate_step_output(): void
    {
        $GLOBALS['__ana_test_posts'][77] = ['post_title' => 'A Title', 'post_content' => 'Some content here.'];

        $result = $this->action()->execute($this->context([], ['generate' => ['post_id' => 77]]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(77, $result->output['post_id']);
    }

    public function test_fails_when_post_not_found(): void
    {
        $result = $this->action()->execute($this->context(['post_id' => 404]));

        $this->assertTrue($result->isFailure());
    }

    public function test_derives_and_persists_seo_metadata(): void
    {
        $longContent = str_repeat('word ', 60) . 'end.';
        $GLOBALS['__ana_test_posts'][5] = [
            'post_title'   => '<b>A Very Important Announcement</b>',
            'post_content' => "<p>{$longContent}</p>",
        ];

        $result = $this->action()->execute($this->context(['post_id' => 5]));

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $this->dispatched);
        $this->assertSame(5, $this->dispatched[0]->postId);

        $seo = $this->seoRepository->findByPostId(5);
        $this->assertInstanceOf(DraftSeo::class, $seo);
        $this->assertSame('A Very Important Announcement', $seo->metaTitle);
        $this->assertStringNotContainsString('<', $seo->metaDescription);
        $this->assertLessThanOrEqual(156, mb_strlen($seo->metaDescription));
        $this->assertSame('announcement', $seo->focusKeyword);
        $this->assertSame('index,follow', $seo->robotsDirectives);
        $this->assertNull($seo->canonicalUrl);
    }

    public function test_running_twice_updates_rather_than_duplicates(): void
    {
        $GLOBALS['__ana_test_posts'][5] = ['post_title' => 'Title', 'post_content' => 'Content.'];

        $this->action()->execute($this->context(['post_id' => 5]));
        $this->action()->execute($this->context(['post_id' => 5]));

        $rows = $this->wpdb->get_results('SELECT * FROM `wp_ana_draft_seo`', ARRAY_A);
        $this->assertCount(1, $rows);
    }
}
