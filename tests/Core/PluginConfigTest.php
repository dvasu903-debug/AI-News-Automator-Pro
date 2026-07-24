<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Core;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Config\PluginConfig;
use PHPUnit\Framework\TestCase;

final class PluginConfigTest extends TestCase
{
    private function makeConfig(): PluginConfig
    {
        return new PluginConfig(
            version: '2.0.0',
            pluginFile: '/var/www/plugins/ai-news-automator-pro/ai-news-automator-pro.php',
            pluginDir: '/var/www/plugins/ai-news-automator-pro/',
            pluginUrl: 'https://site.test/wp-content/plugins/ai-news-automator-pro/',
            environment: Environment::Production,
        );
    }

    public function test_exposes_version(): void
    {
        $this->assertSame('2.0.0', $this->makeConfig()->version());
    }

    public function test_path_joins_relative_segment(): void
    {
        $this->assertSame(
            '/var/www/plugins/ai-news-automator-pro/src/Core',
            $this->makeConfig()->path('src/Core')
        );
    }

    public function test_path_handles_leading_slash_in_relative(): void
    {
        $this->assertSame(
            '/var/www/plugins/ai-news-automator-pro/assets/admin.css',
            $this->makeConfig()->path('/assets/admin.css')
        );
    }

    public function test_url_joins_relative_segment(): void
    {
        $this->assertSame(
            'https://site.test/wp-content/plugins/ai-news-automator-pro/assets/x.js',
            $this->makeConfig()->url('assets/x.js')
        );
    }

    public function test_default_text_domain(): void
    {
        $this->assertSame('ai-news-automator', $this->makeConfig()->textDomain());
    }

    public function test_environment_is_exposed(): void
    {
        $this->assertTrue($this->makeConfig()->environment()->isProduction());
    }

    public function test_unknown_feature_flag_defaults_to_disabled(): void
    {
        $this->assertFalse($this->makeConfig()->isFeatureEnabled('nonexistent_flag'));
    }
}
