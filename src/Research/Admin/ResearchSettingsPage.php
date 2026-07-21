<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Admin;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Settings\AbstractSettingsPage;
use AINewsAutomator\Core\Settings\SettingsField;
use AINewsAutomator\Core\Settings\SettingsSection;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\Health\ResearchHealthCheck;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Health\HealthStatus;

/**
 * Settings + diagnostics page for the Research module. Extends Core's
 * AbstractSettingsPage — the 6th module to do so.
 */
final class ResearchSettingsPage extends AbstractSettingsPage
{
    public function __construct(
        ConfigRepositoryInterface $config,
        LoggerInterface $logger,
        private readonly SessionRepositoryInterface $sessions,
        private readonly ResearchHealthCheck $healthCheck,
    ) {
        parent::__construct($config, $logger);
    }

    public function slug(): string
    {
        return 'ana-research';
    }

    public function pageTitle(): string
    {
        return __('AI News Automator — Research', 'ai-news-automator');
    }

    public function menuTitle(): string
    {
        return __('Research', 'ai-news-automator');
    }

    public function capability(): string
    {
        return Capabilities::RUN_PIPELINE;
    }

    public function sections(): array
    {
        return [
            new SettingsSection(
                'extraction',
                __('Extraction', 'ai-news-automator'),
                [
                    SettingsField::text(
                        'extraction_model',
                        __('Extraction model', 'ai-news-automator'),
                        __('AI model used for claim/entity extraction and contradiction detection.', 'ai-news-automator'),
                        'claude-sonnet-5'
                    ),
                ]
            ),
        ];
    }

    /**
     * Extends the base settings form with recent sessions and health —
     * via AbstractSettingsPage's renderAfterForm() hook, not by
     * overriding the (final) render() itself.
     */
    protected function renderAfterForm(): void
    {
        echo '<div class="wrap" style="margin-top:2em;">';
        $this->renderRecentSessions();
        $this->renderHealth();
        echo '</div>';
    }

    private function renderRecentSessions(): void
    {
        echo '<h2>' . esc_html__('Recent Completed Sessions', 'ai-news-automator') . '</h2>';
        $sessions = $this->sessions->byStatus(SessionStatus::Completed, 20);

        if ($sessions === []) {
            echo '<p>' . esc_html__('No completed research sessions yet.', 'ai-news-automator') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Topic', 'Confidence', 'Completed'] as $heading) {
            echo '<th>' . esc_html__($heading, 'ai-news-automator') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($sessions as $session) {
            echo '<tr>';
            echo '<td>' . esc_html($session->topic) . '</td>';
            echo '<td>' . esc_html($session->confidenceScore !== null ? sprintf('%.0f%%', $session->confidenceScore * 100) : '—') . '</td>';
            echo '<td>' . esc_html($session->completedAt?->format('Y-m-d H:i') ?? '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderHealth(): void
    {
        echo '<h2 style="margin-top:2em;">' . esc_html__('Research Health', 'ai-news-automator') . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Check', 'ai-news-automator') . '</th><th>' . esc_html__('Status', 'ai-news-automator') . '</th><th>' . esc_html__('Detail', 'ai-news-automator') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->healthCheck->run() as $result) {
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
