<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Config;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;

/**
 * Merges a defaults array (injected, not hardcoded — see config-defaults.php)
 * with a single wp_options override entry, exposing both via dot-notation
 * paths. Only overrides are ever persisted; defaults live in code so a
 * plugin upgrade can change a default without a migration.
 */
final class OptionBackedConfigRepository implements ConfigRepositoryInterface
{
    private const OPTION_KEY = 'ai_news_automator_config_overrides';

    /** @var array<string, mixed>|null Lazily computed merge of defaults + overrides. */
    private ?array $merged = null;

    /**
     * @param array<string, mixed> $defaults
     */
    public function __construct(private readonly array $defaults)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->arrayGet($this->resolved(), $key);

        return $value ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $overrides = $this->overrides();
        $this->arraySet($overrides, $key, $value);

        update_option(self::OPTION_KEY, $overrides, false);

        // Invalidate the cache so the next get() reflects this change.
        $this->merged = null;
    }

    public function has(string $key): bool
    {
        return $this->arrayGet($this->resolved(), $key) !== null;
    }

    public function all(): array
    {
        return $this->resolved();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolved(): array
    {
        if ($this->merged === null) {
            $this->merged = $this->mergeRecursive($this->defaults, $this->overrides());
        }

        return $this->merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $array
     */
    private function arrayGet(array $array, string $dotKey): mixed
    {
        if (array_key_exists($dotKey, $array)) {
            return $array[$dotKey];
        }

        $segments = explode('.', $dotKey);
        $cursor = $array;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $array
     */
    private function arraySet(array &$array, string $dotKey, mixed $value): void
    {
        $segments = explode('.', $dotKey);
        $cursor = &$array;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $cursor[$segment] = $value;
                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }
}
