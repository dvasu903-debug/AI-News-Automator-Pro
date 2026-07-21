<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class ApprovalRejectedEvent extends WorkflowEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly int $approvalId,
        public readonly int $decidedBy,
        public readonly ?string $reason,
    ) {
        parent::__construct($metadata);
    }
}
