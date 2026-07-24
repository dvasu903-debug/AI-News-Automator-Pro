<?php
/**
 * Raised when AI-generated draft content cannot be produced — no
 * reviewed prompt template configured, or the provider's response
 * could not be interpreted as content. See GenerateAction for how this
 * maps to a (non-retryable) WorkflowStepException.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Exceptions;

use RuntimeException;

class ContentGenerationException extends RuntimeException
{
    public static function noTemplateConfigured(string $templateName): self
    {
        return new self(sprintf(
            'No reviewed prompt template named "%s" is configured. '
            . 'An administrator must save a template version before content generation can proceed.',
            $templateName
        ));
    }

    public static function malformedResponse(string $templateName): self
    {
        return new self(sprintf(
            'The AI provider\'s response for template "%s" could not be interpreted as content '
            . '(expected a JSON object with "title" and "body" string fields).',
            $templateName
        ));
    }
}
