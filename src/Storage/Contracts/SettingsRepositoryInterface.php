<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Wraps wp_options for per-page settings storage (see module README for
 * why Settings deliberately stays options-backed rather than a new
 * table). This is still a proper repository behind an interface — no
 * business logic calls get_option/update_option directly outside Storage.
 */
interface SettingsRepositoryInterface
{
    public function get(string $page, string $key, mixed $default = null): mixed;

    /**
     * @return array<string, mixed>
     */
    public function all(string $page): array;

    public function set(string $page, string $key, mixed $value): void;

    /**
     * @param array<string, mixed> $values
     */
    public function setMany(string $page, array $values): void;
}
