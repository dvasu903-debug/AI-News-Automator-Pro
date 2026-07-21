<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Support;

/**
 * Single source of UUID-v4 generation for the plugin. Prefers WordPress's
 * wp_generate_uuid4() and falls back to an RFC-4122-compliant implementation
 * for non-WordPress contexts (unit tests). Extracted so correlation IDs and
 * event IDs share one implementation rather than duplicating it.
 */
final class Uuid
{
    public static function v4(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
