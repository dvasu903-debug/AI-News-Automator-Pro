<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Request;

use AINewsAutomator\Security\Contracts\NonceManagerInterface;

/**
 * Wraps WordPress nonces with plugin-namespaced actions. Namespacing means
 * a nonce minted for "ana:settings.save" can never satisfy a check for
 * "ana:pipeline.run", so a nonce leaked from one form can't be replayed
 * against a different action.
 *
 * Nonces remain WordPress's CSRF primitive: they are tied to the user's
 * session and expire, so a cross-site attacker who can't read the page
 * can't obtain a valid one.
 */
final class NonceManager implements NonceManagerInterface
{
    private const PREFIX = 'ana:';

    public function create(string $action): string
    {
        return wp_create_nonce(self::PREFIX . $action);
    }

    public function verify(string $nonce, string $action): bool
    {
        // wp_verify_nonce returns 1|2|false; treat anything falsy as failure.
        return (bool) wp_verify_nonce($nonce, self::PREFIX . $action);
    }

    public function fieldName(string $action): string
    {
        // A stable, action-specific field/header name.
        return '_ana_nonce_' . preg_replace('/[^a-z0-9_]/i', '_', $action);
    }
}
