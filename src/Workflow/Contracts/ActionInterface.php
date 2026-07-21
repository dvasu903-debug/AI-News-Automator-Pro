<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

/**
 * A single unit of work a workflow step can perform. Every future
 * module's action (e.g. Research's StartResearchAction, Publishing's
 * PublishDraftAction) implements this and registers itself via
 * ActionRegistryInterface — Workflow itself never has compile-time
 * knowledge of any specific action beyond its own generic ones
 * (Actions/). See MODULE_7_WORKFLOW_ENGINE_DESIGN.md §2.2.
 */
interface ActionInterface
{
    /**
     * Matches a step's "action" key in the workflow definition JSON.
     * Must be unique across every registered action.
     */
    public function type(): string;

    /**
     * Executes the action synchronously, or opts into asynchronous
     * completion by returning ActionResult::deferred($queueJobId) — see
     * §2.3 step 6 and the approved Decision 3.
     */
    public function execute(WorkflowRunContext $context): ActionResult;
}
