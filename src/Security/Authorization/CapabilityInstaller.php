<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

/**
 * Adds and removes the plugin's custom capabilities on the WordPress roles,
 * driven by Capabilities::roleMap(). Called from SecurityServiceProvider's
 * activate()/uninstall() lifecycle hooks. Idempotent: re-running add is safe.
 */
final class CapabilityInstaller
{
    public function install(): void
    {
        $roleMap = apply_filters('ai_news_automator_role_capabilities', Capabilities::roleMap());

        foreach ($roleMap as $roleSlug => $capabilities) {
            $role = get_role($roleSlug);

            if ($role === null) {
                continue;
            }

            foreach ($capabilities as $capability) {
                if (!$role->has_cap($capability)) {
                    $role->add_cap($capability);
                }
            }
        }
    }

    public function uninstall(): void
    {
        // Remove every custom cap from every role, regardless of the current
        // map, so a cap granted under an old map is still cleaned up.
        $roles = wp_roles();

        foreach (array_keys($roles->roles) as $roleSlug) {
            $role = get_role($roleSlug);

            if ($role === null) {
                continue;
            }

            foreach (Capabilities::all() as $capability) {
                if ($role->has_cap($capability)) {
                    $role->remove_cap($capability);
                }
            }
        }
    }
}
