<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Admin;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Settings\AbstractSettingsPage;
use AINewsAutomator\Core\Settings\SettingsField;
use AINewsAutomator\Core\Settings\SettingsSection;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Workflow\Health\WorkflowHealthCheck;

/**
 * Settings + diagnostics page for the Workflow module. Extends Core's
 * AbstractSettingsPage (7th module to do so), matching the same scope
 * discipline Sources' and Research's settings pages used: global
 * defaults + a health panel, with workflow authoring/run management
 * left to the REST API + a future dedicated admin UI, not this options
 * page.
 */
final class WorkflowSettingsPage extends AbstractSettingsPage
{
    public function __construct(
        ConfigRepositoryInterface $config,
        LoggerInterface $logger,
        private readonly WorkflowHealthCheck $healthCheck,
    ) {
        parent::__construct($config, $logger);
    }

    public function slug(): string
    {
        return 'ana-workflow';
    }

    public function pageTitle(): string
    {
        return __('AI News Automator — Workflow', 'ai-news-automator');
    }

    public function menuTitle(): string
    {
        return __('Workflow', 'ai-news-automator');
    }

    public function capability(): string
    {
        return Capabilities::RUN_PIPELINE;
    }

    public function sections(): array
    {
        return [
            new SettingsSection(
                'execution',
                __('Execution', 'ai-news-automator'),
                [
                    SettingsField::number(
                        'step_retry_max_attempts',
                        __('Max retry attempts per step', 'ai-news-automator'),
                        __('How many times WorkflowStepRetryExecutor retries a retryable step failure before giving up.', 'ai-news-automator'),
                        3
                    ),
                    SettingsField::checkbox(
                        'scheduler_enabled',
                        __('Enable scheduled workflow triggers', 'ai-news-automator'),
                        __('When disabled, WorkflowScheduler\'s cron tick still fires but enqueues no new scheduled runs.', 'ai-news-automator'),
                        true
                    ),
                ]
            ),
        ];
    }
}
