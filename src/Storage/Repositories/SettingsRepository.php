<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Storage\Contracts\SettingsRepositoryInterface;

/**
 * Wraps wp_options for per-page settings — deliberately NOT table-backed
 * (see module README, "settings stays on wp_options"). Still a proper
 * repository behind an interface: this is the only class in the plugin
 * that calls get_option/update_option for plugin settings pages, so a
 * future change to the backing store (should one ever be needed) is a
 * container rebinding, not a search-and-replace across every module.
 *
 * One wp_options row per settings page, keyed `ana_settings_{page}` —
 * consistent with Core's AbstractSettingsPage::optionName() convention.
 */
final class SettingsRepository implements SettingsRepositoryInterface
{
    public function get(string $page, string $key, mixed $default = null): mixed
    {
        $all = $this->all($page);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function all(string $page): array
    {
        $stored = get_option($this->optionName($page), []);

        return is_array($stored) ? $stored : [];
    }

    public function set(string $page, string $key, mixed $value): void
    {
        $all = $this->all($page);
        $all[$key] = $value;
        update_option($this->optionName($page), $all, false);
    }

    public function setMany(string $page, array $values): void
    {
        $all = array_merge($this->all($page), $values);
        update_option($this->optionName($page), $all, false);
    }

    private function optionName(string $page): string
    {
        return 'ana_' . str_replace('-', '_', $page);
    }
}
