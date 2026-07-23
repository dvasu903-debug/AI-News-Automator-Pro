<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\DTO;

/**
 * The result of EditorialPolicyInterface::evaluate() — every violation
 * collected, not just the first (same discipline as Milestone 2's
 * PublishingProfileValidator), so a caller can surface everything an
 * editor needs to fix in one pass.
 */
final class EditorialPolicyResult
{
    /**
     * @param list<string> $violations
     */
    private function __construct(
        public readonly array $violations,
    ) {
    }

    public static function passed(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $violations
     */
    public static function violated(array $violations): self
    {
        return new self($violations);
    }

    public function passes(): bool
    {
        return $this->violations === [];
    }
}
