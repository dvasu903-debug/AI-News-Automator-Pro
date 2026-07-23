<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\DTO;

/**
 * The output of ContentGeneratorInterface::generate() — already
 * sanitized (see ADR-0019, decision 3) by the time a caller receives
 * it. GenerateAction persists this as-is; it does not re-sanitize.
 */
final class GeneratedContent
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
    ) {
    }
}
