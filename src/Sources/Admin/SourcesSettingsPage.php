<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Admin;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Settings\AbstractSettingsPage;
use AINewsAutomator\Core\Settings\SettingsField;
use AINewsAutomator\Core\Settings\SettingsSection;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Health\HealthStatus;
use AINewsAutomator\Sources\Health\SourceHealthCheck;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;

/**
 * Settings + diagnostics page for the Sources module. Extends Core's
 * AbstractSettingsPage (5th module to do so) for ordinary options-backed
 * config; source CRUD itself is left to a future REST/admin-UI pass
 * (out of this module's approved scope) — this page focuses on global
 * defaults and the health/reputation panel, matching how prior modules'
 * settings pages scoped themselves.
 */
final class SourcesSettingsPage extends AbstractSettingsPage
{
    public function __construct(
        ConfigRepositoryInterface $config,
        LoggerInterface $logger,
        private readonly SourceHealthCheck $healthCheck,
        private readonly SourceRepositoryInterface $sources,
    ) {
        parent::__construct($config, $logger);
    }

    public function slug(): string
    {
        return 'ana-sources';
    }

    public function pageTitle(): string
    {
        return __('AI News Automator — Sources', 'ai-news-automator');
    }

    public function menuTitle(): string
    {
        return __('Sources', 'ai-news-automator');
    }

    public function capability(): string
    {
        return Capabilities::MANAGE_SOURCES;
    }

    public function sections(): array
    {
        return [
            new SettingsSection(
                'crawling',
                __('Crawling Defaults', 'ai-news-automator'),
                [
                    SettingsField::text(
                        'default_user_agent',
                        __('Default crawler user agent', 'ai-news-automator'),
                        __('Sent with every crawl/sitemap request and checked against robots.txt.', 'ai-news-automator'),
                        'AINewsAutomatorBot/1.0 (+https://example.com/bot)'
                    ),
                    SettingsField::number(
                        'max_links_per_crawl',
                        __('Max links per crawl', 'ai-news-automator'),
                        __('Upper bound on links extracted from a single crawled page.', 'ai-news-automator'),
                        50
                    ),
                ]
            ),
            new SettingsSection(
                'retention',
                __('Deduplication Retention', 'ai-news-automator'),
                [
                    SettingsField::number(
                        'fingerprint_retention_days',
                        __('Fingerprint retention (days)', 'ai-news-automator'),
                        __('How long dedup fingerprints are kept before being purged.', 'ai-news-automator'),
                        90
                    ),
                ]
            ),
        ];
    }

    /**
     * Extends the base settings form with the sources overview and
     * health — via AbstractSettingsPage's renderAfterForm() hook, not by
     * overriding the (final) render() itself.
     */
    protected function renderAfterForm(): void
    {
        echo '<div class="wrap" style="margin-top:2em;">';
        $this->renderSourcesOverview();
        $this->renderHealth();
        echo '</div>';
    }

    private function renderSourcesOverview(): void
    {
        echo '<h2>' . esc_html__('Configured Sources', 'ai-news-automator') . '</h2>';
        $page = $this->sources->paginate(1, 50);

        if ($page->items === []) {
            echo '<p>' . esc_html__('No sources configured yet.', 'ai-news-automator') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Name', 'Type', 'Enabled', 'Last Fetched'] as $heading) {
            echo '<th>' . esc_html__($heading, 'ai-news-automator') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($page->items as $source) {
            echo '<tr>';
            echo '<td>' . esc_html($source->name) . '</td>';
            echo '<td>' . esc_html($source->type) . '</td>';
            echo '<td>' . ($source->enabled ? esc_html__('Yes', 'ai-news-automator') : esc_html__('No', 'ai-news-automator')) . '</td>';
            echo '<td>' . ($source->lastFetchedAt !== null ? esc_html($source->lastFetchedAt->format('Y-m-d H:i')) : esc_html__('Never', 'ai-news-automator')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderHealth(): void
    {
        echo '<h2 style="margin-top:2em;">' . esc_html__('Source Health', 'ai-news-automator') . '</h2>';
        $results = $this->healthCheck->run();

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Check', 'ai-news-automator') . '</th><th>' . esc_html__('Status', 'ai-news-automator') . '</th><th>' . esc_html__('Detail', 'ai-news-automator') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($results as $result) {
            $color = match ($result->status) {
                HealthStatus::Ok       => '#00a32a',
                HealthStatus::Warning  => '#dba617',
                HealthStatus::Critical => '#d63638',
            };

            echo '<tr><td>' . esc_html($result->name) . '</td>';
            echo '<td><span style="color:' . esc_attr($color) . ';font-weight:600;">' . esc_html(ucfirst($result->status->value)) . '</span></td>';
            echo '<td>' . esc_html($result->message) . '</td></tr>';
        }

        echo '</tbody></table>';
    }
}
