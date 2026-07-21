<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * The single authorization entry point every module MUST use instead of
 * calling current_user_can() directly. Delegates to the PolicyEngine so
 * that an ability check runs every registered policy, is audited, and
 * emits events — none of which happens with a raw capability call.
 */
interface CapabilityGateInterface
{
    /**
     * Returns true if the current actor is permitted the given ability.
     * Never throws — for the throwing variant use authorize().
     *
     * @param array<string, mixed> $context Extra context passed to policies
     *                                       (e.g. the target post ID).
     */
    public function allows(string $ability, array $context = []): bool;

    /**
     * Asserts the ability, throwing AuthorizationException (which callers
     * translate to a 403) when denied.
     *
     * @param array<string, mixed> $context
     *
     * @throws \AINewsAutomator\Security\Exceptions\AuthorizationException
     */
    public function authorize(string $ability, array $context = []): void;
}
