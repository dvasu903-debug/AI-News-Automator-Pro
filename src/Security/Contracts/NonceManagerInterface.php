<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * Wraps WordPress nonce primitives with plugin-namespaced actions, so a
 * nonce minted for one plugin action can't be replayed against another.
 */
interface NonceManagerInterface
{
    public function create(string $action): string;

    /**
     * Verifies a nonce for an action. Returns false on any failure
     * (missing, expired, wrong action) — fails closed.
     */
    public function verify(string $nonce, string $action): bool;

    /**
     * Returns the nonce field name used in forms/headers for an action.
     */
    public function fieldName(string $action): string;
}
