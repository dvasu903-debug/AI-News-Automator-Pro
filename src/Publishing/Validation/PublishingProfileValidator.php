<?php
/**
 * PublishingProfileValidator.
 *
 * REVISION NOTE (r3, Module 3 compatibility audit): the r2 draft
 * validated author/categories/taxonomies/schedule/channels inside
 * `config`. None of those fields have any basis in the real
 * ana_publishing_profiles design (planning/MODULE_8_PUBLISHING_ENGINE_
 * DESIGN.md §3) — `config` there is described only as a free-form
 * LONGTEXT JSON payload, with no fields specified. That entire
 * validation surface was invented against an assumed feature set and
 * is removed here rather than carried forward speculatively. This
 * validator now covers only the columns the frozen + Milestone-2
 * migrations actually define: slug, name, vertical, workflow_key,
 * approval_mode, config's JSON-encodability and schema_version tag.
 *
 * REVISION (r4, per owner constraint): approval_mode is NOT validated
 * against a fixed value list. Nothing in the schema, the design doc, or
 * elsewhere in the repository enumerates its allowed values beyond the
 * column default ('manual') — inventing a set here would be exactly the
 * kind of unauthorized scope the r3 audit already had to strip out
 * once (see the removed author/categories/taxonomies/schedule/channels
 * validation). approval_mode is treated as an opaque persisted string
 * with only structural validation (non-empty, within the column's
 * VARCHAR(30) width) until either an existing enum is found elsewhere
 * in the codebase or one is introduced through an approved ADR.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Validation;

use AINewsAutomator\Publishing\Contracts\PublishingProfileValidatorInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\ProfileValidationException;

final class PublishingProfileValidator implements PublishingProfileValidatorInterface
{
    private const RESERVED_SLUGS = ['default', 'new', 'edit', 'all', 'active', 'inactive'];

    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const SUPPORTED_SCHEMA_VERSIONS = [PublishingProfile::CONFIG_SCHEMA_VERSION];

    // Column widths per Migration_20260722100001 (frozen): slug/name
    // VARCHAR(191); vertical VARCHAR(50); workflow_key VARCHAR(191);
    // approval_mode VARCHAR(30).
    private const MAX_SLUG_LENGTH = 191;
    private const MAX_NAME_LENGTH = 191;
    private const MAX_VERTICAL_LENGTH = 50;
    private const MAX_WORKFLOW_KEY_LENGTH = 191;
    private const MAX_APPROVAL_MODE_LENGTH = 30;

    public function validate(PublishingProfile $profile): void
    {
        $errors = [];

        $this->validateName($profile, $errors);
        $this->validateSlug($profile, $errors);
        $this->validateVertical($profile, $errors);
        $this->validateWorkflowKey($profile, $errors);
        $this->validateApprovalMode($profile, $errors);
        $this->validateSchemaVersion($profile, $errors);
        $this->validateConfigEncodable($profile, $errors);

        if ([] !== $errors) {
            throw new ProfileValidationException($errors);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateName(PublishingProfile $profile, array &$errors): void
    {
        $name = trim($profile->name());

        if ('' === $name) {
            $errors[] = 'Profile name must not be empty.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = sprintf('Profile name exceeds %d characters.', self::MAX_NAME_LENGTH);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateSlug(PublishingProfile $profile, array &$errors): void
    {
        $slug = $profile->slug();

        if ('' === $slug) {
            $errors[] = 'Profile slug must not be empty.';

            return;
        }

        if (mb_strlen($slug) > self::MAX_SLUG_LENGTH) {
            $errors[] = sprintf('Profile slug exceeds %d characters.', self::MAX_SLUG_LENGTH);
        }

        if (1 !== preg_match(self::SLUG_PATTERN, $slug)) {
            $errors[] = sprintf(
                'Profile slug "%s" is invalid; expected lowercase alphanumerics separated by single hyphens.',
                $slug
            );
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            $errors[] = sprintf('Profile slug "%s" is reserved.', $slug);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateVertical(PublishingProfile $profile, array &$errors): void
    {
        $vertical = trim($profile->vertical());

        if ('' === $vertical) {
            $errors[] = 'Profile vertical must not be empty.';

            return;
        }

        if (mb_strlen($vertical) > self::MAX_VERTICAL_LENGTH) {
            $errors[] = sprintf('Profile vertical exceeds %d characters.', self::MAX_VERTICAL_LENGTH);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateWorkflowKey(PublishingProfile $profile, array &$errors): void
    {
        // Structural validation only. Confirming the key resolves to a
        // real Module 7 workflow would require a cross-module dependency
        // on the Workflow registry, which is out of scope for this
        // milestone and not authorized by the approved Milestone 2
        // design (Publishing depends on Core/Storage only per
        // PublishingServiceProvider's docblock). Existence is the
        // responsibility of whichever milestone wires profiles to
        // WorkflowRunner.
        $workflowKey = trim($profile->workflowKey());

        if ('' === $workflowKey) {
            $errors[] = 'Profile workflow_key must not be empty.';

            return;
        }

        if (mb_strlen($workflowKey) > self::MAX_WORKFLOW_KEY_LENGTH) {
            $errors[] = sprintf('Profile workflow_key exceeds %d characters.', self::MAX_WORKFLOW_KEY_LENGTH);
        }
    }

    /**
     * Structural only — see class docblock. No fixed value list.
     *
     * @param string[] $errors
     */
    private function validateApprovalMode(PublishingProfile $profile, array &$errors): void
    {
        $mode = trim($profile->approvalMode());

        if ('' === $mode) {
            $errors[] = 'Profile approval_mode must not be empty.';

            return;
        }

        if (mb_strlen($mode) > self::MAX_APPROVAL_MODE_LENGTH) {
            $errors[] = sprintf('Profile approval_mode exceeds %d characters.', self::MAX_APPROVAL_MODE_LENGTH);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateSchemaVersion(PublishingProfile $profile, array &$errors): void
    {
        $version = $profile->configSchemaVersion();

        if (!in_array($version, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            $errors[] = sprintf(
                'Unsupported config schema version %d; supported: %s.',
                $version,
                implode(', ', self::SUPPORTED_SCHEMA_VERSIONS)
            );
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateConfigEncodable(PublishingProfile $profile, array &$errors): void
    {
        // config is LONGTEXT JSON with no defined internal shape beyond
        // the schema_version convention — the only structural guarantee
        // this validator can make is that it round-trips through JSON
        // cleanly, matching what configJson() will do at persist time.
        $encoded = wp_json_encode($profile->config());

        if (false === $encoded) {
            $errors[] = 'Profile config could not be encoded as JSON: ' . json_last_error_msg();
        }
    }
}
