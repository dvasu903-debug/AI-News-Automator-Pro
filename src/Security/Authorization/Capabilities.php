<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

/**
 * The plugin's custom capabilities and their default role assignments.
 *
 * WordPress's built-in caps (manage_options, publish_posts) are too coarse
 * for an editorial team: someone should be able to approve AI-drafted
 * content without also being able to change API keys or security settings.
 * These fine-grained caps make that separation possible. They map to
 * abilities used throughout the plugin via the DefaultCapabilityPolicy.
 */
final class Capabilities
{
    public const MANAGE_SETTINGS  = 'ana_manage_settings';
    public const MANAGE_SECURITY  = 'ana_manage_security';
    public const MANAGE_SOURCES   = 'ana_manage_sources';
    public const APPROVE_CONTENT  = 'ana_approve_content';
    public const RUN_PIPELINE     = 'ana_run_pipeline';
    public const VIEW_ANALYTICS   = 'ana_view_analytics';
    public const VIEW_AUDIT_LOG   = 'ana_view_audit_log';

    /**
     * @return list<string> Every custom capability.
     */
    public static function all(): array
    {
        return [
            self::MANAGE_SETTINGS,
            self::MANAGE_SECURITY,
            self::MANAGE_SOURCES,
            self::APPROVE_CONTENT,
            self::RUN_PIPELINE,
            self::VIEW_ANALYTICS,
            self::VIEW_AUDIT_LOG,
        ];
    }

    /**
     * Default capability grants per role. Administrators get everything;
     * editors get content-facing caps but not settings/security. This map
     * is applied at activation and is filterable so a site can customize it.
     *
     * @return array<string, list<string>> Role slug => capabilities.
     */
    public static function roleMap(): array
    {
        return [
            'administrator' => self::all(),
            'editor' => [
                self::APPROVE_CONTENT,
                self::RUN_PIPELINE,
                self::VIEW_ANALYTICS,
                self::MANAGE_SOURCES,
            ],
        ];
    }

    /**
     * Maps an ability string to the capability that grants it. Abilities
     * are the vocabulary the rest of the plugin uses (e.g. "content.approve");
     * this indirection means the underlying capability can change without
     * every caller changing.
     *
     * @return array<string, string> ability => capability
     */
    public static function abilityMap(): array
    {
        return [
            'settings.manage'  => self::MANAGE_SETTINGS,
            'security.manage'  => self::MANAGE_SECURITY,
            'sources.manage'   => self::MANAGE_SOURCES,
            'content.approve'  => self::APPROVE_CONTENT,
            'pipeline.run'     => self::RUN_PIPELINE,
            'analytics.view'   => self::VIEW_ANALYTICS,
            'audit.view'       => self::VIEW_AUDIT_LOG,
        ];
    }
}
