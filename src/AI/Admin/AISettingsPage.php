<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Admin;

use AINewsAutomator\AI\Contracts\ProviderRegistryInterface;
use AINewsAutomator\AI\Health\AIProviderHealthCheck;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Settings\AbstractSettingsPage;
use AINewsAutomator\Core\Settings\SettingsField;
use AINewsAutomator\Core\Settings\SettingsSection;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Contracts\CapabilityGateInterface;
use AINewsAutomator\Security\Contracts\NonceManagerInterface;
use AINewsAutomator\Security\Health\HealthStatus;
use AINewsAutomator\Security\Secrets\CredentialVault;

/**
 * Extends Core's AbstractSettingsPage for non-secret configuration
 * (default provider per capability, failover priority/exclusions —
 * ordinary options-backed fields). API keys are deliberately NOT part of
 * that standard fields flow — they're routed through Security's
 * CredentialVault via a separate form/handler, exactly like Storage's
 * secrets never touch wp_options.
 */
final class AISettingsPage extends AbstractSettingsPage
{
    private const API_KEY_NONCE_ACTION = 'ai.save_provider_key';

    public function __construct(
        ConfigRepositoryInterface $config,
        LoggerInterface $logger,
        private readonly CredentialVault $secrets,
        private readonly CapabilityGateInterface $gate,
        private readonly NonceManagerInterface $nonces,
        private readonly ProviderRegistryInterface $registry,
        private readonly AIProviderHealthCheck $healthCheck,
    ) {
        parent::__construct($config, $logger);
    }

    public function slug(): string
    {
        return 'ana-ai';
    }

    public function pageTitle(): string
    {
        return __('AI News Automator — AI Providers', 'ai-news-automator');
    }

    public function menuTitle(): string
    {
        return __('AI Providers', 'ai-news-automator');
    }

    public function capability(): string
    {
        return Capabilities::MANAGE_SETTINGS;
    }

    public function sections(): array
    {
        return [
            new SettingsSection(
                'defaults',
                __('Default Providers', 'ai-news-automator'),
                [
                    SettingsField::text(
                        'default_chat_provider',
                        __('Default chat provider', 'ai-news-automator'),
                        __('Provider id used when no explicit override is given, e.g. "claude".', 'ai-news-automator'),
                        'claude'
                    ),
                ]
            ),
            new SettingsSection(
                'failover',
                __('Failover', 'ai-news-automator'),
                [
                    SettingsField::textarea(
                        'failover_priority',
                        __('Failover priority order', 'ai-news-automator'),
                        __('One provider id per line, highest priority first.', 'ai-news-automator')
                    ),
                    SettingsField::textarea(
                        'failover_excluded',
                        __('Excluded from failover', 'ai-news-automator'),
                        __('One provider id per line. These are never chosen as a failover target.', 'ai-news-automator')
                    ),
                ]
            ),
        ];
    }

    /**
     * Extends the base settings form with the API-key form and provider
     * health — via AbstractSettingsPage's renderAfterForm() hook, not by
     * overriding the (final) render() itself.
     */
    protected function renderAfterForm(): void
    {
        echo '<div class="wrap" style="margin-top:2em;">';
        $this->renderApiKeyForm();
        $this->renderProviderHealth();
        echo '</div>';
    }

    private function renderApiKeyForm(): void
    {
        echo '<h2>' . esc_html__('Provider API Keys', 'ai-news-automator') . '</h2>';
        echo '<p>' . esc_html__('Keys are encrypted at rest via the Security module\'s credential vault, never stored in plugin settings.', 'ai-news-automator') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ana_ai_save_key" />';
        $fieldName = $this->nonces->fieldName(self::API_KEY_NONCE_ACTION);
        $nonceValue = $this->nonces->create(self::API_KEY_NONCE_ACTION);
        echo '<input type="hidden" name="' . esc_attr($fieldName) . '" value="' . esc_attr($nonceValue) . '" />';
        echo '<table class="form-table"><tr>';
        echo '<th scope="row"><label for="ana_ai_provider_id">' . esc_html__('Provider', 'ai-news-automator') . '</label></th>';
        echo '<td><select name="provider_id" id="ana_ai_provider_id">';
        foreach (['claude', 'openai', 'gemini', 'openrouter', 'deepseek', 'grok'] as $id) {
            echo '<option value="' . esc_attr($id) . '">' . esc_html($id) . '</option>';
        }
        echo '</select></td></tr><tr>';
        echo '<th scope="row"><label for="ana_ai_api_key">' . esc_html__('API Key', 'ai-news-automator') . '</label></th>';
        echo '<td><input type="password" name="api_key" id="ana_ai_api_key" class="regular-text" autocomplete="off" /></td>';
        echo '</tr></table>';
        submit_button(__('Save Key', 'ai-news-automator'));
        echo '</form>';
    }

    private function renderProviderHealth(): void
    {
        echo '<h2 style="margin-top:2em;">' . esc_html__('Provider Health', 'ai-news-automator') . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Provider', 'ai-news-automator') . '</th><th>' . esc_html__('Status', 'ai-news-automator') . '</th><th>' . esc_html__('Detail', 'ai-news-automator') . '</th>';
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

    /**
     * admin-post handler for the API key form. Hooked by AIServiceProvider.
     */
    public function handleSaveApiKey(): void
    {
        $nonceField = $this->nonces->fieldName(self::API_KEY_NONCE_ACTION);
        $nonce = isset($_POST[$nonceField]) ? sanitize_text_field(wp_unslash($_POST[$nonceField])) : '';

        if (!$this->nonces->verify($nonce, self::API_KEY_NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed.', 'ai-news-automator'), 403);
        }

        $this->gate->authorize('settings.manage');

        $providerId = isset($_POST['provider_id']) ? sanitize_key(wp_unslash($_POST['provider_id'])) : '';
        $apiKey = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';

        if ($providerId !== '' && $apiKey !== '') {
            $this->secrets->setWithMetadata('ai.' . $providerId . '.api_key', $apiKey, $providerId);
        }

        wp_redirect(admin_url('admin.php?page=' . $this->slug() . '&key_saved=1'));
        exit;
    }
}
