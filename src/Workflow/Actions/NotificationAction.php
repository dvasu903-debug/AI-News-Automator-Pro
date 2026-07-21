<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Actions;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\Contracts\RollbackableActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\RollbackResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;
use AINewsAutomator\Workflow\Entities\WorkflowStepResult;

/**
 * Sends a notification via WordPress's own mail transport
 * (wp_mail — no new outbound channel introduced). Implements
 * RollbackableActionInterface deliberately to return NotReversible
 * rather than silently not implementing the interface at all — this is
 * the canonical example the design doc calls out in §2.5: a sent
 * notification genuinely cannot be undone, and the Runner should record
 * that explicitly rather than skip rollback for this step silently.
 */
final class NotificationAction implements RollbackableActionInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function type(): string
    {
        return 'notification';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $to = (string) ($context->stepConfig['to'] ?? '');
        $subject = (string) ($context->stepConfig['subject'] ?? 'AI News Automator notification');
        $message = (string) ($context->stepConfig['message'] ?? '');

        if ($to === '') {
            return ActionResult::failure('Notification action requires a "to" address in step config.');
        }

        $sent = function_exists('wp_mail') ? wp_mail($to, $subject, $message) : false;

        if (!$sent) {
            $this->logger->warning('Workflow notification failed to send to {to}.', ['to' => $to]);
            return ActionResult::failure('wp_mail() failed to send the notification.');
        }

        return ActionResult::success(['sent_to' => $to]);
    }

    public function rollback(WorkflowStepResult $result): RollbackResult
    {
        return RollbackResult::notReversible('A sent notification cannot be un-sent.');
    }
}
