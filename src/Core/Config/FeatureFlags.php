<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Config;

/**
 * Immutable feature-flag set. Flags let a module ship code that is
 * present but dormant until switched on — useful for staged rollouts
 * of a risky capability (e.g. fully-automatic publishing) without a
 * separate code branch.
 *
 * Flags are resolved once at construction from three layers, in
 * ascending precedence: hardcoded defaults, then any values supplied
 * by the PluginConfig factory, then the `ai_news_automator_feature_flags`
 * filter (so a site owner's mu-plugin can force a flag on or off). The
 * result is frozen — a request sees one consistent set of flags start
 * to finish.
 */
final class FeatureFlags
{
    /**
     * @param array<string, bool> $flags
     */
    private function __construct(private readonly array $flags)
    {
    }

    /**
     * @param array<string, bool> $overrides
     */
    public static function create(array $overrides = []): self
    {
        $defaults = [
            // No features are gated yet. Later modules register their own
            // flags here as they introduce staged-rollout capabilities.
            // Example (Publishing module): 'auto_publish' => false,
        ];

        $merged = array_merge($defaults, $overrides);

        if (function_exists('apply_filters')) {
            /**
             * @var array<string, bool> $merged
             * Filter: ai_news_automator_feature_flags
             */
            $merged = apply_filters('ai_news_automator_feature_flags', $merged);
        }

        return new self($merged);
    }

    public function isEnabled(string $flag): bool
    {
        return ($this->flags[$flag] ?? false) === true;
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->flags;
    }
}
