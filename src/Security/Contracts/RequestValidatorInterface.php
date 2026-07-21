<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * Combines nonce verification, capability authorization, and (optionally)
 * rate limiting into a single guard, so an admin-post/AJAX handler can
 * validate everything in one fail-closed call.
 */
interface RequestValidatorInterface
{
    /**
     * Validates a state-changing request. Throws on the first failure.
     *
     * @param string      $ability    Ability required (checked via the gate).
     * @param string      $nonceAction Nonce action to verify.
     * @param string|null $nonceValue  Supplied nonce; if null, read from the
     *                                 request using the action's field name.
     * @param string|null $rateLimitKey Optional key to rate-limit this request.
     *
     * @throws \AINewsAutomator\Security\Exceptions\SecurityException
     */
    public function validate(
        string $ability,
        string $nonceAction,
        ?string $nonceValue = null,
        ?string $rateLimitKey = null
    ): void;
}
