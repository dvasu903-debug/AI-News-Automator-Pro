<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Settings;

/**
 * A titled group of SettingsField objects, rendered together on a
 * settings page via WordPress's add_settings_section().
 */
final class SettingsSection
{
    /**
     * @param list<SettingsField> $fields
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $fields,
        public readonly string $description = '',
    ) {
    }
}
