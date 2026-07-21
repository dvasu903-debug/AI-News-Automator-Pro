<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * Contract for internal, dot-notation system configuration — distinct
 * from user-facing form Settings (see Settings\AbstractSettingsPage).
 * Config is for structured values the plugin itself depends on
 * internally (retention limits, REST namespace, default capabilities)
 * that ship with sane defaults and are only occasionally overridden,
 * not filled out via an admin form.
 */
interface ConfigRepositoryInterface
{
    /**
     * @param string $key Dot-notation path, e.g. "logging.max_entries".
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Persists an override for the given key. Only the override is
     * stored — defaults are never written back, so a future change to
     * a default takes effect for every site that never explicitly
     * overrode that key.
     */
    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    /**
     * @return array<string, mixed> The fully merged (defaults + overrides) configuration tree.
     */
    public function all(): array;
}
