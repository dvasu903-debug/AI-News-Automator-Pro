<?php
/**
 * Raised when a publishing profile violates validation or business
 * rules. Carries the complete list of violations.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Exceptions;

use RuntimeException;

class ProfileValidationException extends RuntimeException
{
    /**
     * @var string[]
     */
    private array $errors;

    /**
     * @param string[] $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;

        parent::__construct(
            'Publishing profile validation failed: ' . implode(' | ', $errors)
        );
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
