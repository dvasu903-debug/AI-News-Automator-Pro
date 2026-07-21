<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Admin;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Settings\AbstractSettingsPage;
use AINewsAutomator\Core\Settings\SettingsField;
use AINewsAutomator\Core\Settings\SettingsSection;
use AINewsAutomator\Security\Audit\AuditLogger;
use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;
use AINewsAutomator\Security\Health\HealthStatus;
use AINewsAutomator\Security\Health\SecurityHealthCheck;

/**
 * Security configuration + diagnostics screen. Extends Core's
 * AbstractSettingsPage for the settings form, and overrides render() to
 * append a diagnostics panel (health checks), live metrics, and recent audit
 * entries beneath the form. This is the one admin surface for the Security
 * module; it deliberately uses the module's own gate/capability for access
 * (via the page capability), demonstrating the "everything goes through
 * Security" rule on the module itself.
 */
final class SecuritySettingsPage extends AbstractSettingsPage
{
    public function __construct(
        ConfigRepositoryInterface $config,
        LoggerInterface $logger,
        private readonly SecurityHealthCheck $healthCheck,
        private readonly SecurityMetricsInterface $metrics,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($config, $logger);
    }

    public function slug(): string
    {
        return 'ana-security';
    }

    public function pageTitle(): string
    {
        return __('AI News Automator — Security', 'ai-news-automator');
    }

    public function menuTitle(): string
    {
        return __('Security', 'ai-news-automator');
    }

    public function capability(): string
    {
        // Security settings require the dedicated security-management cap,
        // not generic manage_options — fine-grained by design.
        return \AINewsAutomator\Security\Authorization\Capabilities::MANAGE_SECURITY;
    }

    public function sections(): array
    {
        return [
            new SettingsSection(
                'rate_limiting',
                __('Rate Limiting', 'ai-news-automator'),
                [
                    SettingsField::checkbox(
                        'rate_limiting_enabled',
                        __('Enable rate limiting', 'ai-news-automator'),
                        __('Throttle expensive and abusable actions.', 'ai-news-automator'),
                        true
                    ),
                    SettingsField::checkbox(
                        'rate_limit_fail_closed',
                        __('Fail closed on limiter error', 'ai-news-automator'),
                        __('If the rate-limit backend errors, block the action instead of allowing it. Leave off unless you require strict enforcement.', 'ai-news-automator'),
                        false
                    ),
                ],
                __('Controls for request throttling.', 'ai-news-automator')
            ),
            new SettingsSection(
                'audit',
                __('Audit & Threat Detection', 'ai-news-automator'),
                [
                    SettingsField::checkbox(
                        'audit_enabled',
                        __('Enable audit logging', 'ai-news-automator'),
                        __('Record security-relevant actions (authorization, secret access, settings changes).', 'ai-news-automator'),
                        true
                    ),
                    SettingsField::checkbox(
                        'threat_detection_enabled',
                        __('Enable threat detection', 'ai-news-automator'),
                        __('Correlate repeated failures and emit alerts.', 'ai-news-automator'),
                        true
                    ),
                ]
            ),
        ];
    }

    /**
     * Extends the base settings form with diagnostics, metrics, and
     * audit trail — via AbstractSettingsPage's renderAfterForm() hook,
     * not by overriding the (final) render() itself.
     */
    protected function renderAfterForm(): void
    {
        echo '<div class="wrap" style="margin-top:2em;">';
        $this->renderDiagnostics();
        $this->renderMetrics();
        $this->renderAuditTrail();
        echo '</div>';
    }

    private function renderDiagnostics(): void
    {
        echo '<h2>' . esc_html__('Security Diagnostics', 'ai-news-automator') . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Check', 'ai-news-automator') . '</th>';
        echo '<th>' . esc_html__('Status', 'ai-news-automator') . '</th>';
        echo '<th>' . esc_html__('Detail', 'ai-news-automator') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->healthCheck->run() as $result) {
            $color = match ($result->status) {
                HealthStatus::Ok       => '#00a32a',
                HealthStatus::Warning  => '#dba617',
                HealthStatus::Critical => '#d63638',
            };

            echo '<tr>';
            echo '<td><strong>' . esc_html($result->name) . '</strong></td>';
            echo '<td><span style="color:' . esc_attr($color) . ';font-weight:600;">'
                . esc_html(ucfirst($result->status->value)) . '</span></td>';
            echo '<td>' . esc_html($result->message);
            if ($result->recommendation !== '') {
                echo '<br><em>' . esc_html($result->recommendation) . '</em>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private function renderMetrics(): void
    {
        echo '<h2 style="margin-top:2em;">' . esc_html__('Security Metrics', 'ai-news-automator') . '</h2>';
        $metrics = $this->metrics->all();

        if ($metrics === []) {
            echo '<p>' . esc_html__('No metrics recorded yet.', 'ai-news-automator') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><tbody>';
        foreach ($metrics as $name => $value) {
            echo '<tr><td><strong>' . esc_html((string) $name) . '</strong></td><td>'
                . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderAuditTrail(): void
    {
        echo '<h2 style="margin-top:2em;">' . esc_html__('Recent Audit Entries', 'ai-news-automator') . '</h2>';
        $entries = $this->audit->recent(20);

        if ($entries === []) {
            echo '<p>' . esc_html__('No audit entries yet.', 'ai-news-automator') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (['Time', 'Actor', 'Action', 'Target', 'Result'] as $heading) {
            echo '<th>' . esc_html__($heading, 'ai-news-automator') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            echo '<tr>';
            echo '<td>' . esc_html(gmdate('Y-m-d H:i:s', $entry->timestamp)) . '</td>';
            echo '<td>' . esc_html($entry->actorLogin !== '' ? $entry->actorLogin : (string) $entry->actorId) . '</td>';
            echo '<td>' . esc_html($entry->action) . '</td>';
            echo '<td>' . esc_html($entry->target) . '</td>';
            echo '<td>' . esc_html($entry->result->value) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
