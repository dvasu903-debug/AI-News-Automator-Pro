<?php
/**
 * Covers GenerateAction: step-config validation, the
 * AIException/ContentGenerationException -> WorkflowStepException
 * retry-classification bridge (ADR-0019), draft creation, and
 * DraftGeneratedEvent dispatch.
 *
 * @package AINewsAutomator\Tests\Publishing\Actions
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Actions;

use AINewsAutomator\AI\Exceptions\AIErrorType;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Publishing\Actions\GenerateAction;
use AINewsAutomator\Publishing\DTO\GeneratedContent;
use AINewsAutomator\Publishing\Events\DraftGeneratedEvent;
use AINewsAutomator\Publishing\Exceptions\ContentGenerationException;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Tests\Publishing\Fakes\FakeContentGenerator;
use AINewsAutomator\Tests\Publishing\Fakes\FakeDraftRepository;
use AINewsAutomator\Tests\Publishing\Fakes\FakeSessionRepository;
use AINewsAutomator\Tests\Publishing\Fakes\ResearchSummaryFixture;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;
use AINewsAutomator\Workflow\Entities\WorkflowStepErrorType;
use AINewsAutomator\Workflow\Retry\WorkflowStepException;
use PHPUnit\Framework\TestCase;

final class GenerateActionTest extends TestCase
{
    private FakeContentGenerator $generator;
    private FakeSessionRepository $sessions;
    private FakeDraftRepository $drafts;
    private EventDispatcher $events;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->generator = new FakeContentGenerator();
        $this->sessions = new FakeSessionRepository();
        $this->sessions->summarizeReturn = ResearchSummaryFixture::build(sessionId: 7);
        $this->drafts = new FakeDraftRepository();
        $this->events = new EventDispatcher();
        $this->dispatched = [];

        $this->events->addListener(DraftGeneratedEvent::class, function (object $e): void {
            $this->dispatched[] = $e;
        });
    }

    private function action(): GenerateAction
    {
        return new GenerateAction(
            $this->generator,
            $this->sessions,
            $this->drafts,
            $this->events,
            new EventMetadataFactory(new CorrelationContext('test'))
        );
    }

    private function context(array $stepConfig): WorkflowRunContext
    {
        return new WorkflowRunContext(1, 'generate', 'corr-1', $stepConfig, []);
    }

    public function test_requires_research_session_id(): void
    {
        $result = $this->action()->execute($this->context([]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('research_session_id', $result->error);
        $this->assertSame([], $this->drafts->createCalls);
    }

    public function test_session_state_exception_from_summarize_is_a_plain_failure(): void
    {
        $this->sessions->summarizeThrows = SessionStateException::notGathering(7, 'gathering');

        $result = $this->action()->execute($this->context(['research_session_id' => 7]));

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $this->drafts->createCalls);
    }

    public function test_content_generation_exception_becomes_non_retryable_workflow_step_exception(): void
    {
        $this->generator->generateThrows = ContentGenerationException::noTemplateConfigured('publishing.article_generation');

        try {
            $this->action()->execute($this->context(['research_session_id' => 7]));
            $this->fail('Expected WorkflowStepException.');
        } catch (WorkflowStepException $e) {
            $this->assertSame(WorkflowStepErrorType::Validation, $e->errorType());
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_retryable_ai_exception_becomes_transient_workflow_step_exception(): void
    {
        $this->generator->generateThrows = new AIException('Rate limited.', AIErrorType::RateLimited, 'claude');

        try {
            $this->action()->execute($this->context(['research_session_id' => 7]));
            $this->fail('Expected WorkflowStepException.');
        } catch (WorkflowStepException $e) {
            $this->assertSame(WorkflowStepErrorType::Transient, $e->errorType());
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_non_retryable_ai_exception_becomes_validation_workflow_step_exception(): void
    {
        $this->generator->generateThrows = new AIException('Bad request.', AIErrorType::Validation, 'claude');

        try {
            $this->action()->execute($this->context(['research_session_id' => 7]));
            $this->fail('Expected WorkflowStepException.');
        } catch (WorkflowStepException $e) {
            $this->assertSame(WorkflowStepErrorType::Validation, $e->errorType());
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_success_creates_draft_and_dispatches_event(): void
    {
        $this->generator->generateReturn = new GeneratedContent('A Title', 'A body.');
        $this->drafts->createReturn = 55;

        $result = $this->action()->execute($this->context(['research_session_id' => 7]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(55, $result->output['post_id']);
        $this->assertCount(1, $this->drafts->createCalls);
        $this->assertSame('A Title', $this->drafts->createCalls[0]['title']);
        $this->assertSame('A body.', $this->drafts->createCalls[0]['content']);
        $this->assertSame(7, $this->drafts->createCalls[0]['meta']['_ana_research_session_id']);
        $this->assertCount(1, $this->dispatched);
        $this->assertSame(55, $this->dispatched[0]->postId);
        $this->assertSame(7, $this->dispatched[0]->researchSessionId);
    }
}
