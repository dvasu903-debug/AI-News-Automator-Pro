<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Config;

/**
 * The central, immutable configuration object for the plugin. Owns every
 * piece of "who and where am I" information that was previously scattered
 * across global constants (ANA_PRO_VERSION, ANA_PRO_DIR, ANA_PRO_URL, ...).
 *
 * Why an object instead of constants: constants are global state that
 * can't be substituted in a test, can't be namespaced, and encourage
 * any class anywhere to reach for them directly (the exact coupling this
 * refactor is removing). A PluginConfig instance is injected wherever
 * it's needed, so a test can construct one with fake paths, and a class's
 * dependence on configuration is visible in its constructor signature.
 *
 * The main plugin file still defines a couple of constants (version and
 * file path) because a WordPress plugin header and the activation-hook
 * registration genuinely need file-scope values before any autoloading
 * has happened — but those constants are read in exactly one place (the
 * PluginConfig factory below) and never referenced anywhere else.
 */
final class PluginConfig
{
    private ?FeatureFlags $featureFlags = null;

    /**
     * @param non-empty-string $version
     * @param non-empty-string $pluginFile  Absolute path to the main plugin file.
     * @param non-empty-string $pluginDir   Absolute path to the plugin directory (trailing slash).
     * @param non-empty-string $pluginUrl   Public URL to the plugin directory (trailing slash).
     * @param non-empty-string $textDomain
     * @param array<string, bool> $featureFlagOverrides
     */
    public function __construct(
        private readonly string $version,
        private readonly string $pluginFile,
        private readonly string $pluginDir,
        private readonly string $pluginUrl,
        private readonly Environment $environment,
        private readonly string $textDomain = 'ai-news-automator',
        private readonly array $featureFlagOverrides = [],
    ) {
    }

    /**
     * Builds a PluginConfig from the main plugin file, deriving directory
     * and URL from WordPress path helpers. This is the ONE place that
     * reads the file-scope constants/plugin path — everywhere else uses
     * the resulting object.
     *
     * @param non-empty-string $pluginFile
     */
    public static function fromPluginFile(string $pluginFile, Environment $environment): self
    {
        $version = defined('ANA_PRO_VERSION') ? (string) ANA_PRO_VERSION : '0.0.0';

        return new self(
            version: $version !== '' ? $version : '0.0.0',
            pluginFile: $pluginFile,
            pluginDir: plugin_dir_path($pluginFile),
            pluginUrl: plugin_dir_url($pluginFile),
            environment: $environment,
        );
    }

    public function version(): string
    {
        return $this->version;
    }

    public function pluginFile(): string
    {
        return $this->pluginFile;
    }

    /**
     * Absolute filesystem path inside the plugin directory.
     */
    public function path(string $relative = ''): string
    {
        return $this->pluginDir . ltrim($relative, '/');
    }

    /**
     * Public URL inside the plugin directory.
     */
    public function url(string $relative = ''): string
    {
        return $this->pluginUrl . ltrim($relative, '/');
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function textDomain(): string
    {
        return $this->textDomain;
    }

    public function featureFlags(): FeatureFlags
    {
        return $this->featureFlags ??= FeatureFlags::create($this->featureFlagOverrides);
    }

    public function isFeatureEnabled(string $flag): bool
    {
        return $this->featureFlags()->isEnabled($flag);
    }
}
