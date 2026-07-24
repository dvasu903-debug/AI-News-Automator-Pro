<?php
/**
 * PublishingProfileRepositoryInterface.
 *
 * Persistence-only contract (Milestone 2 refinement #1, unchanged by
 * the r3 schema correction). setActive()/activate()/deactivate() were
 * already consolidated to a single method in r2; r3 renames it to
 * setEnabled() to match the real `enabled` column (was `is_active` in
 * the r2 assumed schema).
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Contracts;

use AINewsAutomator\Publishing\DTO\PublishingProfile;

interface PublishingProfileRepositoryInterface
{
    public function create(PublishingProfile $profile): PublishingProfile;

    public function update(PublishingProfile $profile): PublishingProfile;

    public function delete(int $profileId): bool;

    public function findById(int $profileId): ?PublishingProfile;

    public function findBySlug(string $slug): ?PublishingProfile;

    /**
     * @return PublishingProfile[]
     */
    public function findAll(bool $enabledOnly = false): array;

    /**
     * Returns the explicitly marked default profile, or null. No
     * implicit fallback — policy for "no default configured" belongs to
     * the service layer (PublishingProfileService::requireDefault()).
     */
    public function findDefault(): ?PublishingProfile;

    /**
     * Atomically marks one record as the default, demoting any previous
     * default within the same transaction.
     */
    public function markDefault(int $profileId): void;

    public function setEnabled(int $profileId, bool $enabled): void;

    /**
     * Persistence-level uniqueness probe used by the service layer.
     */
    public function existsWithSlug(string $slug, ?int $excludeId = null): bool;

    /**
     * Persistence-level uniqueness probe used by the service layer.
     */
    public function existsWithName(string $name, ?int $excludeId = null): bool;
}
