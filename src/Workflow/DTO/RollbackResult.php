<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\DTO;

/**
 * The outcome of RollbackableActionInterface::rollback(). Three honest
 * outcomes, matching §2.5 — rollback is best-effort, not transactional.
 */
final class RollbackResult
{
    private function __construct(
        public readonly RollbackOutcome $outcome,
        public readonly ?string $detail,
    ) {
    }

    public static function rolledBack(?string $detail = null): self
    {
        return new self(RollbackOutcome::RolledBack, $detail);
    }

    public static function rollbackFailed(string $detail): self
    {
        return new self(RollbackOutcome::RollbackFailed, $detail);
    }

    public static function notReversible(?string $detail = null): self
    {
        return new self(RollbackOutcome::NotReversible, $detail);
    }
}
