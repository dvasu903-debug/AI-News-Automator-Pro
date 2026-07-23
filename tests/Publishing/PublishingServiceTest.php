<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Publishing\Events\ArticleArchivedEvent;
use AINewsAutomator\Publishing\Events\ArticlePublishedEvent;
use AINewsAutomator\Publishing\Events\ArticleScheduledEvent;
use AINewsAutomator\Publishing\Events\ArticleUnpublishedEvent;
use AINewsAutomator\Publishing\Events\PublishingFailedEvent;
use AINewsAutomator\Publishing\Services\PublishingService;
use AINewsAutomator\Tests\Publishing\Fakes\FakeArticleRepository;
use AINewsAutomator\Tests\Publishing\Fakes\FakeDraftRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers PublishingService's four PublisherInterface operations per
 * ADR-0018: publish() branches on isGenerated() (reuses
 * ArticleRepositoryInterface::approve() for AI-generated drafts, direct
 * wp_update_post() otherwise); schedule()/unpublish()/archive() always
 * use wp_update_post() directly. Every operation dispatches its
 * Publishing-scoped event on success and PublishingFailedEvent on
 * failure.
 */
final class PublishingServiceTest extends TestCase
{
    private FakeArticleRepository $articles;
    private FakeDraftRepository $drafts;
    private EventDispatcher $events;
    private PublishingService $service;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_posts'] = [];

        $this->articles = new FakeArticleRepository();
        $this->drafts = new FakeDraftRepository();
        $this->events = new EventDispatcher();
        $this->dispatched = [];

        foreach ([
            ArticlePublishedEvent::class,
            ArticleScheduledEvent::class,
            ArticleUnpublishedEvent::class,
            ArticleArchivedEvent::class,
            PublishingFailedEvent::class,
        ] as $eventClass) {
            $this->events->addListener($eventClass, function (object $e): void {
                $this->dispatched[] = $e;
            });
        }

        $this->service = new PublishingService(
            $this->articles,
            $this->drafts,
            $this->events,
            new EventMetadataFactory(new CorrelationContext('test'))
        );
    }

    public function test_publish_generated_draft_delegates_to_article_repository_approve(): void
    {
        $this->drafts->isGeneratedReturn = true;
        $this->articles->approveReturn = true;

        $result = $this->service->publish(42);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $result->postId);
        $this->assertSame([42], $this->articles->approveCalls);
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ArticlePublishedEvent::class, $this->dispatched[0]);
    }

    public function test_publish_generated_draft_fails_when_approve_returns_false(): void
    {
        $this->drafts->isGeneratedReturn = true;
        $this->articles->approveReturn = false;

        $result = $this->service->publish(42);

        $this->assertTrue($result->isFailed());
        $this->assertInstanceOf(PublishingFailedEvent::class, $this->dispatched[0]);
    }

    public function test_publish_manual_draft_uses_wp_update_post_directly_not_approve(): void
    {
        $this->drafts->isGeneratedReturn = false;

        $result = $this->service->publish(7);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $this->articles->approveCalls, 'approve() must not be called for a manual draft.');
        $this->assertSame('publish', $GLOBALS['__ana_test_posts'][7]['post_status']);
        $this->assertInstanceOf(ArticlePublishedEvent::class, $this->dispatched[0]);
    }

    public function test_schedule_sets_future_status_and_post_date(): void
    {
        $at = new \DateTimeImmutable('2027-01-15 09:00:00', new \DateTimeZone('UTC'));

        $result = $this->service->schedule(10, $at);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($at, $result->at);
        $this->assertSame('future', $GLOBALS['__ana_test_posts'][10]['post_status']);
        $this->assertSame('2027-01-15 09:00:00', $GLOBALS['__ana_test_posts'][10]['post_date']);
        $this->assertInstanceOf(ArticleScheduledEvent::class, $this->dispatched[0]);
    }

    public function test_unpublish_reverts_to_draft_status(): void
    {
        $result = $this->service->unpublish(11);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('draft', $GLOBALS['__ana_test_posts'][11]['post_status']);
        $this->assertInstanceOf(ArticleUnpublishedEvent::class, $this->dispatched[0]);
    }

    public function test_archive_uses_native_private_status(): void
    {
        $result = $this->service->archive(12);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('private', $GLOBALS['__ana_test_posts'][12]['post_status']);
        $this->assertInstanceOf(ArticleArchivedEvent::class, $this->dispatched[0]);
    }
}
